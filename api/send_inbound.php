<?php
/**
 * send_inbound.php — COMPLETE MINIMAL VERSION (Deterministic, RID + Day + q-code)
 * Date: 10 Mar 2026
 *
 * Valid replies:
 *   - 1..10  => save to mapped *_answer
 *   - 0      => instant opt-out (sets $FIELD_OPT_OUT='0'); no answer save
 *   - HELP   => no save; async HELP auto-reply (rate-limited in outbound)
 *   - other  => invalid; no save; async HELP auto-reply (if enabled) + async outbound trigger
 *
 * Always returns 200 quickly; uses asynchronous fire-and-forget triggers.
 */

require_once __DIR__ . '/config.php';
date_default_timezone_set($TIMEZONE);

/* ---------------- Log directory & file ---------------- */
if (!defined('LOG_DIR')) {
    $try = realpath(__DIR__ . '/../logs');
    if (!$try) $try = __DIR__ . '/logs';
    define('LOG_DIR', $try);
}
if (!is_dir(LOG_DIR)) { @mkdir(LOG_DIR, 0775, true); }

$INBOUND_LOG_FILE = rtrim(LOG_DIR, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '/logs/inbound.log';
function inlog($line){
    global $INBOUND_LOG_FILE;
    @file_put_contents($INBOUND_LOG_FILE, '['.date('Y-m-d H:i:s').'] '.$line.PHP_EOL, FILE_APPEND | LOCK_EX);
}

inlog("=== INBOUND v10 STARTED ===");

/* ---------------- Safe helpers ---------------- */
if (!function_exists('normalise_msisdn_digits')) {
    function normalise_msisdn_digits($phone){
        $s = is_scalar($phone) ? (string)$phone : '';
        $digits = preg_replace('/\D+/', '', $s);
        return $digits ?? '';
    }
}
if (!function_exists('digits_last')) {
    function digits_last($val, $n = 10){
        $s = is_scalar($val) ? (string)$val : '';
        $digits = preg_replace('/\D+/', '', $s);
        if ($digits === null) $digits = '';
        if ($n > 0 && strlen($digits) > $n) {
            return substr($digits, -$n);
        }
        return $digits;
    }
}
function first_line($s){
    if (!is_string($s) || $s === '') return '';
    $parts = preg_split("/\R/", $s, 2);
    return trim($parts[0] ?? '');
}
function sanitize_int_1_10($s){
    $t = trim((string)$s);
    if ($t === '') return null;
    if (!preg_match('/-?\d+/', $t, $m)) return null;
    $v = (int)$m[0];
    if ($v < 1 || $v > 10) return null;
    return $v;
}

/* ---------------- Parse header "CoSMART RID:2 - Day 5:q2a" ---------------- */
function parse_outbound_header($line){
    if (!is_string($line) || $line === '') return [null,null,null];
    $rid = null; $day = null; $q = null;
    if (preg_match('/RID\s*:\s*(\d+)/i', $line, $rm)) $rid = (int)$rm[1];
    if (preg_match('/Day\s*(\d+)\s*[:\-\–]\s*(q\d+[ab])/i', $line, $dm)) {
        $day = (int)$dm[1]; $q = strtolower($dm[2]);
    }
    return [$rid, $day, $q];
}

/* ---------------- Map q-code -> answer field ---------------- */
function map_q_to_answer_field($qcode, $SMSW_FIELD_MAP, $SEQUENCE){
    $qcode = strtolower(trim((string)$qcode));
    if ($qcode === '') return null;

    if (is_array($SMSW_FIELD_MAP)){
        foreach ($SMSW_FIELD_MAP as $m) {
            $q = strtolower($m['q'] ?? ''); $a = $m['a'] ?? '';
            if ($q !== '' && $a !== '' && $q === $qcode) return $a;
        }
    }
    if (is_array($SEQUENCE)){
        foreach ($SEQUENCE as $s) {
            $q = strtolower($s['q'] ?? ''); $a = $s['a'] ?? '';
            if ($q !== '' && $a !== '' && $q === $qcode) return $a;
        }
    }
    return null;
}

/* ---------------- REDCap API polyfills (import) ---------------- */
if (!function_exists('redcap_api_post')) {
    function redcap_api_post($url, $data){
        if (function_exists('curl_init')) {
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL            => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $data,
                CURLOPT_TIMEOUT        => 30,
            ]);
            $resp = curl_exec($ch);
            $err  = curl_error($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($err)  throw new RuntimeException("REDCap API cURL error: $err");
            if ($code < 200 || $code >= 300) throw new RuntimeException("REDCap API HTTP $code: $resp");
            return $resp;
        } else {
            $opts = [
                'http' => [
                    'method'  => 'POST',
                    'header'  => "Content-Type: application/x-www-form-urlencoded\r\n",
                    'content' => http_build_query($data),
                    'timeout' => 30
                ]
            ];
            $ctx  = stream_context_create($opts);
            $resp = @file_get_contents($url, false, $ctx);
            if ($resp === false) {
                $e = error_get_last(); $msg = $e['message'] ?? 'unknown error';
                throw new RuntimeException("REDCap API stream error: $msg");
            }
            return $resp;
        }
    }
}
if (!function_exists('redcap_import_records')) {
    function redcap_import_records($token, $url, array $rows){
        if (empty($url) || empty($token)) {
            throw new RuntimeException("REDCap API config missing in config.php");
        }
        if (empty($rows)) return;
        $payload = [
            'token'             => $token,
            'content'           => 'record',
            'format'            => 'json',
            'type'              => 'flat',
            'overwriteBehavior' => 'normal',
            'data'              => json_encode($rows, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
            'returnContent'     => 'count',
            'returnFormat'      => 'json'
        ];
        $resp = redcap_api_post($url, $payload);
        $j = json_decode($resp, true);
        if (is_array($j) && isset($j['count'])) return;
        if (stripos($resp, 'error') !== false) throw new RuntimeException("REDCap import error: $resp");
    }
}

/* ---------------- Always capture fatals & reply OK ---------------- */
register_shutdown_function(function(){
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        inlog("FATAL: {$err['message']} in {$err['file']}:{$err['line']}");
        http_response_code(200);
        echo "OK";
    }
});

/* ---------------- Fire-and-forget outbound trigger helpers ---------------- */
function outbound_url_autotrigger(array $extra = []): string {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $path   = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
    $qs     = array_merge(['autotrigger'=>1], $extra);
    return $scheme . '://' . $host . $path . '/send_outbound.php?' . http_build_query($qs);
}
function http_fire_and_forget(string $url, int $timeoutSec = 1): bool {
    $parts = parse_url($url);
    if (!$parts || empty($parts['host'])) return false;
    $scheme = $parts['scheme'] ?? 'http';
    $host   = $parts['host'];
    $port   = $parts['port'] ?? (($scheme === 'https') ? 443 : 80);
    $path   = ($parts['path'] ?? '/') . (isset($parts['query']) ? ('?' . $parts['query']) : '');
    $transport = ($scheme === 'https') ? 'ssl://' . $host : $host;
    $fp = @fsockopen($transport, $port, $errno, $errstr, $timeoutSec);
    if (!$fp) return false;
    stream_set_blocking($fp, false);
    $hdr  = "GET {$path} HTTP/1.1\r\n";
    $hdr .= "Host: {$host}\r\n";
    $hdr .= "Connection: Close\r\n\r\n";
    fwrite($fp, $hdr);
    fclose($fp);
    return true;
}
function can_trigger_outbound_now(string $lockFile, int $cooldownSec = 10): bool {
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

    $from     = $payload['from']     ?? '';
    $to       = $payload['to']       ?? '';
    $content  = trim((string)($payload['content']  ?? ''));
    $outbound = (string)($payload['outbound'] ?? '');

    inlog("RECEIVED | from={$from} | content={$content} | outbound=".str_replace(["\r","\n"], ['\\r','\\n'], $outbound));

    // VMN check (null-safe)
    $toDigits  = digits_last($to, 10);
    $vmnDigits = digits_last($SMSW_REPLY_NUMBER ?? '', 10);
    if ($toDigits !== '' && $vmnDigits !== '') {
        if ($toDigits !== $vmnDigits) {
            inlog("IGNORED: wrong destination (toDigits=$toDigits vs vmnDigits=$vmnDigits)");
            http_response_code(200); echo "OK"; return;
        }
    } else {
        if ($toDigits === '') inlog("WARNING: 'to' missing/empty in payload — skipping VMN check.");
        if ($vmnDigits === '') inlog("WARNING: \$SMSW_REPLY_NUMBER missing/empty — skipping VMN check.");
    }
    inlog("VMN check passed (or skipped with warning)");

    // Parse RID, Day, q-code from first line of outbound
    $first = first_line($outbound);
    list($rid, $day, $qcode) = parse_outbound_header($first);

    if (!$rid || !$day || !$qcode) {
        inlog("ABORT: Could not parse RID/Day/q from first line '{$first}'. Expect 'CoSMART RID:2 - Day 5:q2a'");
        // Trigger HELP (if enabled) and normal outbound (non-blocking)
        if (defined('HELP_FOR_INVALID_ENABLED') && HELP_FOR_INVALID_ENABLED && defined('HELP_AUTOREPLY_ENABLED') && HELP_AUTOREPLY_ENABLED) {
            $urlH = outbound_url_autotrigger(['help'=>1, 'rid'=>0, 'inst'=>0, 'reason'=>'parse_fail']);
            http_fire_and_forget($urlH, 1);
            inlog("HELP AUTOREPLY trigger (parse fail) fired");
        }
        $lock = rtrim(LOG_DIR, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '.outbound_trigger.lock';
        if (can_trigger_outbound_now($lock, 10)) { http_fire_and_forget(outbound_url_autotrigger(), 1); inlog("AUTO-TRIGGER outbound: fired (parse fail)"); }
        http_response_code(200); echo "OK"; return;
    }

    // Base row
    $baseRow = [
        'record_id'                => (string)$rid,
        'redcap_event_name'        => $FOLLOWUP_EVENT,
        'redcap_repeat_instrument' => $FOLLOWUP_REPEAT_INSTR,
        'redcap_repeat_instance'   => (string)$day,
    ];

    $upper = strtoupper($content);

    // (1) OPT-OUT when "0"
    if ($content === '0') {
        $opt = $baseRow; $opt[$FIELD_OPT_OUT] = '0';
        inlog(">>> IMPORT ROW (OPT-OUT): ".json_encode($opt, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
        try { redcap_import_records($REDCAP_API_TOKEN, $REDCAP_API_URL, [$opt]); inlog("SUCCESS: Opt-out set @ record={$rid}, instance={$day}"); } catch(Throwable $e){ inlog("ERROR setting opt-out: ".$e->getMessage()); }

        if (!empty($NEXT_SMS_TRIGGER_FIELD)) {
            $touch = $baseRow; $touch[$NEXT_SMS_TRIGGER_FIELD] = date('Y-m-d H:i:s');
            try { redcap_import_records($REDCAP_API_TOKEN, $REDCAP_API_URL, [$touch]); inlog("TRIGGER touched after opt-out"); } catch(Throwable $e){ inlog("ERROR touching trigger: ".$e->getMessage()); }
        }

        $lock = rtrim(LOG_DIR, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '.outbound_trigger.lock';
        if (can_trigger_outbound_now($lock, 10)) { http_fire_and_forget(outbound_url_autotrigger(), 1); inlog("AUTO-TRIGGER outbound: fired (opt-out)"); }
        http_response_code(200); echo "OK"; return;
    }

    // (2) HELP — instant HELP auto-reply (async)
    if ($upper === 'HELP') {
        inlog("HELP received for record={$rid}, instance={$day}, q={$qcode}. No save.");
        if (defined('HELP_AUTOREPLY_ENABLED') && HELP_AUTOREPLY_ENABLED) {
            $url = outbound_url_autotrigger(['help'=>1, 'rid'=>$rid, 'inst'=>$day, 'reason'=>'help']);
            $ok  = http_fire_and_forget($url, 1);
            inlog("HELP AUTOREPLY trigger: " . ($ok ? 'fired' : 'failed'));
        }
        // Normal outbound trigger (optional/harmless)
        $lock = rtrim(LOG_DIR, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '.outbound_trigger.lock';
        if (can_trigger_outbound_now($lock, 10)) { http_fire_and_forget(outbound_url_autotrigger(), 1); inlog("AUTO-TRIGGER outbound: fired (HELP)"); }
        http_response_code(200); echo "OK"; return;
    }

    // (3) Numeric 1..10 -> save
    $answerField = map_q_to_answer_field($qcode, $SMSW_FIELD_MAP ?? [], $SEQUENCE ?? []);
    if (!$answerField) {
        inlog("ABORT: No answer field mapping for q-code '{$qcode}'. Check \$SMSW_FIELD_MAP or \$SEQUENCE.");
        if (defined('HELP_FOR_INVALID_ENABLED') && HELP_FOR_INVALID_ENABLED && defined('HELP_AUTOREPLY_ENABLED') && HELP_AUTOREPLY_ENABLED) {
            $urlH = outbound_url_autotrigger(['help'=>1, 'rid'=>$rid, 'inst'=>$day, 'reason'=>'no_mapping']);
            http_fire_and_forget($urlH, 1);
            inlog("HELP AUTOREPLY trigger (no mapping) fired");
        }
        $lock = rtrim(LOG_DIR, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '.outbound_trigger.lock';
        if (can_trigger_outbound_now($lock, 10)) { http_fire_and_forget(outbound_url_autotrigger(), 1); inlog("AUTO-TRIGGER outbound: fired (no mapping)"); }
        http_response_code(200); echo "OK"; return;
    }

    $score = sanitize_int_1_10($content);
    if ($score !== null) {
        $saveRow = $baseRow; $saveRow[$answerField] = (string)$score;
        inlog(">>> IMPORT ROW (answer): ".json_encode($saveRow, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
        try {
            redcap_import_records($REDCAP_API_TOKEN, $REDCAP_API_URL, [$saveRow]);
            inlog("SUCCESS: Saved {$answerField}={$score} @ record={$rid}, instance={$day}");

            if (!empty($NEXT_SMS_TRIGGER_FIELD)) {
                $touchRow = $baseRow;
                $touchRow[$NEXT_SMS_TRIGGER_FIELD] = date('Y-m-d H:i:s');
                inlog(">>> IMPORT ROW (trigger): ".json_encode($touchRow, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
                redcap_import_records($REDCAP_API_TOKEN, $REDCAP_API_URL, [$touchRow]);
                inlog("POST-SAVE TRIGGER OK");
            }

            // Mark complete at q5b
            $isLastQuestion = (strtolower($qcode) === 'q5b');
            if ($isLastQuestion) {
                $formCompleteField = !empty($FORM_COMPLETE_FIELD) ? $FORM_COMPLETE_FIELD : ($FOLLOWUP_REPEAT_INSTR . '_complete');
                $completeRow = $baseRow; $completeRow[$formCompleteField] = '2';
                inlog(">>> IMPORT ROW (complete): ".json_encode($completeRow, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
                redcap_import_records($REDCAP_API_TOKEN, $REDCAP_API_URL, [$completeRow]);
                inlog("MARKED COMPLETE @ q5b");
            }

        } catch (Throwable $e) {
            inlog("ERROR saving answer: " . $e->getMessage());
        }

        $lock = rtrim(LOG_DIR, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '.outbound_trigger.lock';
        if (can_trigger_outbound_now($lock, 10)) {
            $ok = http_fire_and_forget(outbound_url_autotrigger(), 1);
            inlog("AUTO-TRIGGER outbound: " . ($ok ? "fired" : "failed") . " (after valid answer)");
        } else {
            inlog("AUTO-TRIGGER outbound: suppressed by 10s lock (after valid answer)");
        }

        http_response_code(200);
        echo "OK";
        return;
    }

    // (4) INVALID → send HELP (rate-limited in outbound) + normal outbound trigger
    inlog("INVALID content for record={$rid}, instance={$day}, q={$qcode}: '{$content}' (expected 1..10, 0, or HELP)");
    if (defined('HELP_FOR_INVALID_ENABLED') && HELP_FOR_INVALID_ENABLED && defined('HELP_AUTOREPLY_ENABLED') && HELP_AUTOREPLY_ENABLED) {
        $urlH = outbound_url_autotrigger(['help'=>1, 'rid'=>$rid, 'inst'=>$day, 'reason'=>'invalid']);
        http_fire_and_forget($urlH, 1);
        inlog("HELP AUTOREPLY trigger (invalid) fired");
    }
    $lock = rtrim(LOG_DIR, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '.outbound_trigger.lock';
    if (can_trigger_outbound_now($lock, 10)) {
        http_fire_and_forget(outbound_url_autotrigger(), 1);
        inlog("AUTO-TRIGGER outbound: fired (invalid)");
    }
    http_response_code(200);
    echo "OK";

} catch (Throwable $e) {
    inlog("UNCAUGHT: " . $e->getMessage());
    http_response_code(200);
    echo "OK";
}