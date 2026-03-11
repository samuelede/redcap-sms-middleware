<?php
/**
 * send_inbound_firetext.php — Provider-agnostic inbound for FireText (also works with SMS Works)
 * - Accepts: from, to, message|content|body (POST JSON or form)
 * - Validates replies: 1..10, "0" => opt-out, "HELP" => instant help auto-reply (async)
 * - Saves into latest non-opted-out instance's next unanswered question
 * - Returns 200 OK immediately; triggers send_outbound.php asynchronously (10s stampede lock)
 * - Logs to LOG_DIR/inbound_firetext.log
 */

require_once __DIR__ . '/config.php';
date_default_timezone_set($TIMEZONE);

/* ---------------- Log dir & file ---------------- */
if (!defined('LOG_DIR')) {
    $try = realpath(__DIR__ . '/../logs');
    if (!$try) $try = __DIR__ . '/logs';
    define('LOG_DIR', $try);
}
if (!is_dir(LOG_DIR)) { @mkdir(LOG_DIR, 0775, true); }

$LOG_FILE = rtrim(LOG_DIR, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'inbound_firetext.log';
function inlog($line){
    global $LOG_FILE;
    @file_put_contents($LOG_FILE, '['.date('Y-m-d H:i:s').'] '.$line.PHP_EOL, FILE_APPEND | LOCK_EX);
}

/* ---------------- Helpers ---------------- */
function digits_last($val, $n = 10){
    $s = is_scalar($val) ? (string)$val : '';
    $d = preg_replace('/\D+/', '', $s);
    if ($d === null) $d = '';
    return strlen($d) > $n ? substr($d, -$n) : $d;
}
function first_nonempty(...$args){
    foreach ($args as $a) { if (isset($a) && $a !== '') return $a; }
    return '';
}
function sanitize_int_1_10($s){
    $t = trim((string)$s); if ($t === '') return null;
    if (!preg_match('/-?\d+/', $t, $m)) return null;
    $v = (int)$m[0]; if ($v < 1 || $v > 10) return null;
    return $v;
}
function is_answered_1to10_val($val){
    $t=trim((string)$val);
    return $t!=='' && ctype_digit($t) && (int)$t>=1 && (int)$t<=10;
}
function find_next_unanswered_question($row,$sequence){
    foreach($sequence as $s){
        if(trim((string)($row[$s['a']]??''))==='') return $s['q'];
    }
    return null;
}
function map_q_to_answer_field($qCode, $SEQUENCE){
    $q=trim(strtolower((string)$qCode));
    foreach ($SEQUENCE as $s) {
        if (strtolower($s['q']) === $q) return $s['a'];
    }
    return null;
}

/* ---------------- REDCap helpers ---------------- */
function rc_post($url,$data){
    $ch=curl_init();
    curl_setopt_array($ch,[CURLOPT_URL=>$url, CURLOPT_POST=>true, CURLOPT_RETURNTRANSFER=>true, CURLOPT_SSL_VERIFYPEER=>true, CURLOPT_POSTFIELDS=>$data, CURLOPT_TIMEOUT=>45]);
    $out=curl_exec($ch); $code=curl_getinfo($ch,CURLINFO_HTTP_CODE); $err=curl_error($ch); curl_close($ch);
    if($code!==200) throw new RuntimeException("REDCap API error ($code): $out :: $err");
    $j=json_decode($out,true); return $j ?? $out;
}
function rc_export($fields,$events=[],$filterLogic=''){
    $p=['token'=>$GLOBALS['REDCAP_API_TOKEN'],'content'=>'record','format'=>'json','type'=>'flat','filterLogic'=>$filterLogic];
    $i=0; foreach($fields as $f){ if($f){ $p["fields[$i]"]=$f; $i++; } }
    foreach($events as $j=>$e) $p["events[$j]"]=$e;
    return rc_post($GLOBALS['REDCAP_API_URL'],$p);
}
function rc_import($rows){
    if (empty($rows)) return ['count'=>0];
    return rc_post($GLOBALS['REDCAP_API_URL'],[
        'token'=>$GLOBALS['REDCAP_API_TOKEN'],'content'=>'record','format'=>'json','type'=>'flat',
        'overwriteBehavior'=>'normal','data'=>json_encode($rows),'returnContent'=>'ids','returnFormat'=>'json'
    ]);
}

/* ---------------- Fire-and-forget outbound trigger ---------------- */
function outbound_url_autotrigger(array $extra = []): string {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $path   = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
    $qs     = array_merge(['autotrigger'=>1], $extra);
    return $scheme . '://' . $host . $path . '/send_outbound.php?' . http_build_query($qs);
}
function http_fire_and_forget(string $url, int $timeoutSec = 1): bool {
    $parts = parse_url($url); if (!$parts || empty($parts['host'])) return false;
    $scheme = $parts['scheme'] ?? 'http';
    $host   = $parts['host'];
    $port   = $parts['port'] ?? (($scheme === 'https') ? 443 : 80);
    $path   = ($parts['path'] ?? '/') . (isset($parts['query']) ? ('?' . $parts['query']) : '');
    $transport = ($scheme === 'https') ? 'ssl://' . $host : $host;
    $fp = @fsockopen($transport, $port, $errno, $errstr, $timeoutSec); if (!$fp) return false;
    stream_set_blocking($fp, false);
    $hdr  = "GET {$path} HTTP/1.1\r\nHost: {$host}\r\nConnection: Close\r\n\r\n";
    fwrite($fp, $hdr); fclose($fp); return true;
}
function can_trigger_now(string $lockFile, int $cooldownSec = 10): bool {
    $now = time();
    if (file_exists($lockFile)) {
        $age = $now - (int)@filemtime($lockFile);
        if ($age < $cooldownSec) return false;
    }
    @touch($lockFile);
    return true;
}

/* ---------------- Main ---------------- */
try {
    $raw = file_get_contents('php://input');
    $payload = json_decode($raw, true) ?: $_POST ?: $_GET;

    $from    = first_nonempty($payload['from'] ?? '', $payload['source'] ?? '', $payload['sender'] ?? '');
    $to      = first_nonempty($payload['to'] ?? '', $payload['destination'] ?? '', $payload['recipient'] ?? '', $payload['number'] ?? '');
    $message = first_nonempty($payload['message'] ?? '', $payload['content'] ?? '', $payload['body'] ?? '');

    inlog("INBOUND FT | from={$from} | to={$to} | msg={$message}");

    // VMN guard: allow if matches either FireText or SMS Works reply numbers (last 10 digits), else warn & continue (some FT setups omit 'to')
    $to10   = digits_last($to, 10);
    $ft10   = digits_last($FIRETEXT_REPLY_NUMBER ?? '', 10);
    $smsw10 = digits_last($SMSW_REPLY_NUMBER ?? '', 10);
    if ($to10 !== '') {
        if ($to10 !== $ft10 && $to10 !== $smsw10) {
            inlog("IGNORED: destination mismatch (to10={$to10} vs ft10={$ft10} / smsw10={$smsw10})");
            http_response_code(200); echo "OK"; return;
        }
    } else {
        inlog("WARNING: 'to' missing; VMN check skipped.");
    }

    $msgTrim = trim((string)$message);
    if ($msgTrim === '') {
        inlog("IGNORED: empty message");
        http_response_code(200); echo "OK"; return;
    }

    // Find record by baseline mobile (last 10 digits)
    $baseline = rc_export(['record_id', $FIELD_PHONE], [$BASELINE_EVENT]);
    $rid = null;
    $from10 = digits_last($from, 10);
    foreach ($baseline as $row){
        $ph10 = digits_last($row[$FIELD_PHONE] ?? '', 10);
        if ($ph10 !== '' && $ph10 === $from10) { $rid = (string)$row['record_id']; break; }
    }
    if (!$rid) {
        inlog("NOT FOUND: sender {$from10} not matched to a record");
        http_response_code(200); echo "OK"; return;
    }

    // Load follow-up instances for this record
    $needFields = [$FIELD_DAY_NUMBER, $FIELD_OPT_OUT];
    foreach ($SEQUENCE as $s){ $needFields[]=$s['q']; $needFields[]=$s['a']; }
    $rows = rc_export(array_merge(['record_id'], $needFields), [$FOLLOWUP_EVENT], "[record_id] = '$rid'");

    $instances = [];
    foreach ($rows as $r){
        if (($r['redcap_repeat_instrument'] ?? '') !== $FOLLOWUP_REPEAT_INSTR) continue;
        $inst = (int)($r['redcap_repeat_instance'] ?? 0);
        if ($inst>=1) $instances[$inst] = $r;
    }
    if (!$instances) {
        inlog("Record {$rid} has no follow-up instances");
        http_response_code(200); echo "OK"; return;
    }

    // Pick latest non-opted-out instance
    $keys = array_keys($instances); rsort($keys, SORT_NUMERIC);
    $instActive = null; $rowActive = null;
    foreach ($keys as $i) {
        $r = $instances[$i];
        if (($r[$FIELD_OPT_OUT] ?? '') === '0') continue;
        $instActive = $i; $rowActive = $r; break;
    }
    if ($instActive === null) {
        inlog("Record {$rid}: all instances opted out");
        http_response_code(200); echo "OK"; return;
    }

    // Commands: 0 (opt-out), HELP, 1..10
    $UP = strtoupper($msgTrim);

    // 0 => opt out now
    if ($msgTrim === '0') {
        $upd = [[
            'record_id'                => $rid,
            'redcap_event_name'        => $FOLLOWUP_EVENT,
            'redcap_repeat_instrument' => $FOLLOWUP_REPEAT_INSTR,
            'redcap_repeat_instance'   => $instActive,
            $FIELD_OPT_OUT             => '0'
        ]];
        rc_import($upd); inlog("OPT-OUT set @ record={$rid}, inst={$instActive}");

        // async outbound (update state) + return
        $lock = rtrim(LOG_DIR, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '.outbound_trigger.lock';
        if (can_trigger_now($lock, 10)) { http_fire_and_forget(outbound_url_autotrigger(), 1); inlog("AUTO-TRIGGER outbound fired (opt-out)"); }
        http_response_code(200); echo "OK"; return;
    }

    // HELP => instant help auto-reply (handled in outbound with rate-limiting)
    if ($UP === 'HELP') {
        inlog("HELP received @ record={$rid}, inst={$instActive}");
        $helpLock = rtrim(LOG_DIR, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '.help_trigger.lock';
        if (can_trigger_now($helpLock, 5)) {
            $u = outbound_url_autotrigger(['help'=>1,'rid'=>$rid,'inst'=>$instActive,'reason'=>'help']);
            http_fire_and_forget($u, 1);
            inlog("HELP AUTOREPLY trigger fired");
        }
        // Also trigger normal outbound once
        $lock = rtrim(LOG_DIR, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '.outbound_trigger.lock';
        if (can_trigger_now($lock, 10)) { http_fire_and_forget(outbound_url_autotrigger(), 1); inlog("AUTO-TRIGGER outbound fired (HELP)"); }
        http_response_code(200); echo "OK"; return;
    }

    // Save numeric 1..10 into next unanswered
    $score = sanitize_int_1_10($msgTrim);
    if ($score !== null) {
        // find next unanswered in this instance
        $nextQ = find_next_unanswered_question($rowActive, $SEQUENCE);
        if (!$nextQ) { inlog("No unanswered question in inst {$instActive}; ignoring numeric"); http_response_code(200); echo "OK"; return; }

        $ansField = map_q_to_answer_field($nextQ, $SEQUENCE);
        if (!$ansField)  { inlog("No answer field for {$nextQ}"); http_response_code(200); echo "OK"; return; }

        $save = [[
            'record_id'                => $rid,
            'redcap_event_name'        => $FOLLOWUP_EVENT,
            'redcap_repeat_instrument' => $FOLLOWUP_REPEAT_INSTR,
            'redcap_repeat_instance'   => $instActive,
            $ansField                  => (string)$score
        ]];
        rc_import($save);
        inlog("Saved {$ansField}={$score} @ record={$rid}, inst={$instActive}");

        // async outbound to continue flow
        $lock = rtrim(LOG_DIR, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '.outbound_trigger.lock';
        if (can_trigger_now($lock, 10)) { http_fire_and_forget(outbound_url_autotrigger(), 1); inlog("AUTO-TRIGGER outbound fired (valid score)"); }
        http_response_code(200); echo "OK"; return;
    }

    // Invalid => send the same help message (rate-limited in outbound) + normal trigger
    inlog("INVALID content '{$msgTrim}' @ record={$rid}, inst={$instActive}");
    $sendHelpForInvalid = defined('HELP_FOR_INVALID_ENABLED') ? HELP_FOR_INVALID_ENABLED : true;
    $helpEnabled        = defined('HELP_AUTOREPLY_ENABLED') ? HELP_AUTOREPLY_ENABLED : true;
    if ($sendHelpForInvalid && $helpEnabled) {
        $u = outbound_url_autotrigger(['help'=>1,'rid'=>$rid,'inst'=>$instActive,'reason'=>'invalid']);
        http_fire_and_forget($u, 1);
        inlog("HELP AUTOREPLY trigger fired (invalid)");
    }
    $lock = rtrim(LOG_DIR, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '.outbound_trigger.lock';
    if (can_trigger_now($lock, 10)) { http_fire_and_forget(outbound_url_autotrigger(), 1); inlog("AUTO-TRIGGER outbound fired (invalid)"); }

    http_response_code(200);
    echo "OK";

} catch (Throwable $e) {
    inlog("UNCAUGHT: " . $e->getMessage());
    http_response_code(200);
    echo "OK";
}