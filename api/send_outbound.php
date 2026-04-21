<?php
/**
 * send_outbound.php — with:
 *  - 07:00 guard for q1a (configurable)
 *  - AUTO‑HEAL window 07:00–12:00 (configurable) + summary
 *  - 3‑hour reminder: resend same question once if unanswered (configurable, window-aware)
 *  - Auto opt‑out if any *_answer == "0"
 *  - HELP auto-reply on demand (?help=1&rid=&inst=&reason=help|invalid|parse_fail|no_mapping)
 *      • HELP: rate-limited per record by HELP_RATE_LIMIT_MINUTES (fallback 60)
 *      • INVALID: at most once per calendar day per (record, instance)
 *  - Y-m-d dates, catch-up creation, provider IDs/status, duplicate protection
 *  - Logs to LOG_DIR/send_outbound.log
 *  - State files:
 *      • LOG_DIR/outbound_state.json  (question sends & reminders)
 *      • LOG_DIR/help_state.json      (HELP rate-limiting + invalid-per-day markers)
 */


require_once __DIR__ . '/config.php';
error_log("STEP 1: send_outbound.php loaded");
date_default_timezone_set($TIMEZONE);

/* ------------------------------------------------------------
 * Log directory
 * ------------------------------------------------------------ */
if (!defined('LOG_DIR')) {
    $try = realpath(__DIR__ . '/../logs');
    if (!$try) $try = __DIR__ . '/logs';
    define('LOG_DIR', $try);
}
if (!is_dir(LOG_DIR)) { @mkdir(LOG_DIR, 0775, true); }

$OUTBOUND_LOG_FILE = rtrim(LOG_DIR, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'send_outbound.log';
$STATE_FILE        = rtrim(LOG_DIR, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'outbound_state.json';
$HELP_STATE_FILE   = rtrim(LOG_DIR, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'help_state.json';
$MAX_LOG_BYTES     = 5 * 1024 * 1024;
if (is_file($OUTBOUND_LOG_FILE) && filesize($OUTBOUND_LOG_FILE) > $MAX_LOG_BYTES) {
    @rename($OUTBOUND_LOG_FILE, $OUTBOUND_LOG_FILE . '.' . date('Ymd_His'));
}
function log_to_file($line) {
    global $OUTBOUND_LOG_FILE;
    @file_put_contents($OUTBOUND_LOG_FILE, '['.date('Y-m-d H:i:s').'] '.$line.PHP_EOL, FILE_APPEND | LOCK_EX);
}
function logv($msg){
    global $VERBOSE;
    log_to_file($msg);
    if ($VERBOSE) echo "[", date('H:i:s'), "] ", htmlspecialchars($msg), "<br>\n";
}

/* ------------------------------------------------------------
 * Input flags
 * ------------------------------------------------------------ */
$PROVIDER    = $_GET['provider'] ?? $PROVIDER ?? 'smsworks';
$VERBOSE     = isset($_GET['verbose']) && $_GET['verbose'] !== '0';
$RUN_PRUNE   = (isset($_GET['prune']) && $_GET['prune'] == '1');
$PRUNE_KEEP  = (int)($_GET['keep'] ?? $DEFAULT_PRUNE_KEEP);
if ($PRUNE_KEEP < 0) $PRUNE_KEEP = 0;

if (isset($_GET['autotrigger']) && $_GET['autotrigger']=='1') {
    logv('Outbound invoked via inbound auto-trigger.');
}

// If triggered by REDCap DET (POST), run silently
if (empty($_GET) && !empty($_POST['redcap_project_id'])) {
    $VERBOSE = false;
}

log_to_file(str_repeat('-', 60));
log_to_file('Run started: send_outbound.php provider=' . ($PROVIDER ?? '') . ' time=' . date('c'));

/* ------------------------------------------------------------
 * Config policy (with defaults you asked for)
 * ------------------------------------------------------------ */
function cfg_int($name, $fallback){ return defined($name) ? (int)constant($name) : (int)$fallback; }
function cfg_bool($name, $fallback){ return defined($name) ? (bool)constant($name) : (bool)$fallback; }

$Q1A_HOUR       = cfg_int('Q1A_GUARD_START_HOUR', 7);
$HEAL_START     = cfg_int('AUTO_HEAL_WINDOW_START_HOUR', 7);
$HEAL_END       = cfg_int('AUTO_HEAL_WINDOW_END_HOUR',   20);

$REM_ENABLED    = cfg_bool('REMINDER_ENABLED', true);
$REM_SECS       = cfg_int('REMINDER_SECONDS', 3*3600); //define('REMINDER_SECONDS', 3 * 24 * 3600); in live env
$REM_MAX        = cfg_int('REMINDER_SENT_MAX', 1);
$REM_W_START    = defined('REMINDER_WINDOW_START_HOUR') ? constant('REMINDER_WINDOW_START_HOUR') : null;
$REM_W_END      = defined('REMINDER_WINDOW_END_HOUR')   ? constant('REMINDER_WINDOW_END_HOUR')   : null;

$HELP_ENABLED   = cfg_bool('HELP_AUTOREPLY_ENABLED', true);
/* FALLBACK CHANGED TO 60 MINUTES, per your request */
$HELP_RL_MIN    = cfg_int('HELP_RATE_LIMIT_MINUTES', 60);
$HELP_TEXT      = defined('HELP_AUTOREPLY_TEXT') ? (string)HELP_AUTOREPLY_TEXT :
                  "Reply 1–10 for your score today.\nReply 0 to stop messages.\nIf unsure, reply HELP.";
$HELP_FOR_INVALID = cfg_bool('HELP_FOR_INVALID_ENABLED', true);

/* ------------------------------------------------------------
 * Guard helpers
 * ------------------------------------------------------------ */
function allow_q1a_now_global($hourGuard){ return ((int)date('G') >= (int)$hourGuard); }
function allow_auto_heal_now_global($start,$end){ $h=(int)date('G'); return ($h>=$start && $h<=$end); }
function allow_reminder_window($wStart, $wEnd): bool {
    if ($wStart === null || $wEnd === null || $wStart === '' || $wEnd === '') return true;
    $h = (int)date('G'); return ($h >= (int)$wStart && $h <= (int)$wEnd);
}

/* ------------------------------------------------------------
 * State helpers (reminders & HELP)
 * ------------------------------------------------------------ */
function state_load($file){
    if (!is_file($file)) return [];
    $j = @file_get_contents($file);
    if ($j === false) return [];
    $d = json_decode($j, true);
    return is_array($d) ? $d : [];
}
function state_save($file, array $state){
    $tmp = $file.'.tmp';
    @file_put_contents($tmp, json_encode($state, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
    @rename($tmp, $file);
}

/* Question-send/reminder state */
function q_state_mark_sent(array &$state, $rid, $inst, $qCode){
    $rid = (string)$rid; $inst = (string)$inst; $qCode = (string)$qCode;
    if (!isset($state[$rid])) $state[$rid]=[];
    if (!isset($state[$rid][$inst])) $state[$rid][$inst]=[];
    if (!isset($state[$rid][$inst][$qCode])) $state[$rid][$inst][$qCode]=[];
    if (empty($state[$rid][$inst][$qCode]['first_sent_ts']))
        $state[$rid][$inst][$qCode]['first_sent_ts'] = time();
    if (!isset($state[$rid][$inst][$qCode]['reminder_count']))
        $state[$rid][$inst][$qCode]['reminder_count'] = 0;
}
function q_state_can_remind(array $state, $rid, $inst, $qCode, $ageSec, $maxCount): bool {
    $rid=(string)$rid; $inst=(string)$inst; $q=(string)$qCode;
    if (empty($state[$rid][$inst][$q]['first_sent_ts'])) return false;
    $first = (int)$state[$rid][$inst][$q]['first_sent_ts'];
    $cnt   = (int)($state[$rid][$inst][$q]['reminder_count'] ?? 0);
    if ($cnt >= $maxCount) return false;
    return (time() - $first) >= $ageSec;
}
function q_state_mark_reminded(array &$state, $rid, $inst, $qCode){
    $rid=(string)$rid; $inst=(string)$inst; $q=(string)$qCode;
    if (!isset($state[$rid][$inst][$q])) $state[$rid][$inst][$q]=[];
    $state[$rid][$inst][$q]['reminder_count'] = (int)($state[$rid][$inst][$q]['reminder_count'] ?? 0) + 1;
    $state[$rid][$inst][$q]['reminder_ts']    = time();
}

/* HELP state:
   - last_help_ts per record (for HELP messages, 1h RL)
   - invalid_per_day markers per record->inst->YYYY-MM-DD (for invalid only, 1/day/inst) */
function help_last_ts(array $state, $rid): ?int {
    $rid=(string)$rid; return isset($state[$rid]['last_help_ts']) ? (int)$state[$rid]['last_help_ts'] : null;
}
function help_mark_now(array &$state, $rid){
    $rid=(string)$rid; if (!isset($state[$rid])) $state[$rid]=[];
    $state[$rid]['last_help_ts'] = time();
}
function invalid_already_sent_today(array $state, $rid, $inst, $dateYmd): bool {
    $rid=(string)$rid; $inst=(string)$inst;
    return !empty($state[$rid]['invalid'][$inst][$dateYmd]);
}
function invalid_mark_today(array &$state, $rid, $inst, $dateYmd){
    $rid=(string)$rid; $inst=(string)$inst;
    if (!isset($state[$rid])) $state[$rid]=[];
    if (!isset($state[$rid]['invalid'])) $state[$rid]['invalid']=[];
    if (!isset($state[$rid]['invalid'][$inst])) $state[$rid]['invalid'][$inst]=[];
    $state[$rid]['invalid'][$inst][$dateYmd] = 1;
}

/* ------------------------------------------------------------
 * REDCap helpers
 * ------------------------------------------------------------ */
function redcap_post($url,$data){
    $ch=curl_init();
    curl_setopt_array($ch,[
        CURLOPT_URL=>$url, CURLOPT_POST=>true, CURLOPT_RETURNTRANSFER=>true,
        CURLOPT_SSL_VERIFYPEER=>true, CURLOPT_POSTFIELDS=>$data
    ]);
    $out=curl_exec($ch);
    $code=curl_getinfo($ch,CURLINFO_HTTP_CODE);
    $err=curl_error($ch);
    //curl_close($ch); deprecated in PHP 8.5 — safe to omit

    if ($code!==200) throw new RuntimeException("REDCap API error ($code): $out :: $err");
    $json=json_decode($out,true);
    return $json ?? $out;
}
function redcap_export_records($token,$url,$fields=[],$filterLogic='',$events=[]){
    $p=['token'=>$token,'content'=>'record','format'=>'json','type'=>'flat','filterLogic'=>$filterLogic];
    foreach ($fields as $i=>$f) $p["fields[$i]"]=$f;
    foreach ($events as $i=>$e) $p["events[$i]"]=$e;
    return redcap_post($url,$p);
}
function redcap_import_records($token,$url,$records){
    return redcap_post($url,[
        'token'=>$token,'content'=>'record','format'=>'json','type'=>'flat',
        'overwriteBehavior'=>'normal','data'=>json_encode($records),
        'returnContent'=>'ids','returnFormat'=>'json'
    ]);
}

/* ------------------------------------------------------------
 * Provider helpers
 * ------------------------------------------------------------ */
function normalise_msisdn_for_firetext($raw){
    $d=preg_replace('/\D+/','',$raw??'');
    if($d==='') return '';
    if(strpos($d,'0044')===0) $d='44'.substr($d,4);
    if(strpos($d,'440')===0)  $d='44'.substr($d,3);
    if(strpos($d,'07')===0)   return $d;
    if(strpos($d,'44')===0)   return $d;
    if($d[0]=='0' && isset($d[1]) && $d[1]=='7') return '44'.substr($d,1);
    return $d;
}
function send_sms_firetext($apiKey,$to,$from,$msg,&$xHeader){
    $endpoint="https://www.firetext.co.uk/api/sendsms/json";
    $payload=http_build_query(['apiKey'=>$apiKey,'to'=>$to,'from'=>$from,'message'=>$msg],'','&');
    $respHeaders=[];
    $ch=curl_init();
    curl_setopt_array($ch,[
        CURLOPT_URL=>$endpoint, CURLOPT_POST=>true, CURLOPT_RETURNTRANSFER=>true,
        CURLOPT_POSTFIELDS=>$payload,
        CURLOPT_HEADERFUNCTION=>function($ch,$h)use(&$respHeaders){
            $p=explode(':',$h,2); if(count($p)==2) $respHeaders[strtolower(trim($p[0]))]=trim($p[1]);
            return strlen($h);
        }
    ]);
    $out=curl_exec($ch);
    $code=curl_getinfo($ch,CURLINFO_HTTP_CODE);
    curl_close($ch);
    $xHeader=$respHeaders['x-message']??null;
    if($code!==200) throw new RuntimeException("FireText error ($code): $out");
    return $xHeader;
}
function send_sms_smsworks($jwt, $to, $from, $msg, $deliveryReportUrl=null){

    // ---- FIX: ensure exactly one JWT prefix ----
    $jwt = trim((string)$jwt);
    if (stripos($jwt, 'JWT ') === 0) {
        $authHeader = "Authorization: {$jwt}";
    } else {
        $authHeader = "Authorization: JWT {$jwt}";
    }

    // --------------------------------------------

    $endpoint = "https://api.thesmsworks.co.uk/v1/message/send";

    $toNorm = normalise_msisdn_for_smsworks($to);
    $body = [
        'destination' => $toNorm,
        'sender'      => $from,
        'content'     => $msg
    ];
    if ($deliveryReportUrl) {
        $body['deliveryreporturl'] = $deliveryReportUrl;
    }

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $endpoint,
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            $authHeader,
            'Content-Type: application/json'
        ],
        CURLOPT_POSTFIELDS     => json_encode($body),
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);

    $out  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($code < 200 || $code > 299) {
        throw new RuntimeException("SMS Works error ($code): $out :: $err");
    }

    $resp = json_decode($out, true);
    if (!$resp) {
        throw new RuntimeException("Invalid SMS Works JSON: $out");
    }
    return $resp;
}

/* ------------------------------------------------------------
 * Date + sequencing helpers
 * ------------------------------------------------------------ */
function parse_baseline_date_dmy($raw){
    if(!$raw) return null;
    $raw=trim((string)$raw); if($raw==='') return null;
    $norm=preg_replace('/[\/\.]/','-',$raw);
    foreach(['!d-m-Y','!j-n-Y','!Y-m-d'] as $fmt){
        $dt=DateTime::createFromFormat($fmt,$norm);
        if($dt && $dt->format('Y')>=1900) return $dt;
    }
    return null;
}
function fmt_import_ymd(DateTime $dt){ return $dt->format('Y-m-d'); }
function format_dmy(DateTime $dt){ return $dt->format('d-m-Y'); }
function get_today_day_number($baselineRaw){
    $base=parse_baseline_date_dmy($baselineRaw);
    if(!$base) return null;
    $today=new DateTime('today');
    return (int)$base->diff($today)->format('%a'); // baseline date = Day 0
}
function find_next_unanswered_question($row,$sequence){
    foreach($sequence as $s){
        $ans=trim((string)($row[$s['a']]??''));
        if($ans==='') return $s['q'];
    }
    return null;
}
function is_answered_1to10($val){
    $t=trim((string)$val);
    return $t!=='' && ctype_digit($t) && (int)$t>=1 && (int)$t<=10;
}

// strict within-day prerequisites
$PREV_ANSWER = [
    'q1b' => 'q1a_answer',
    'q2a' => 'q1b_answer',
    'q2b' => 'q2a_answer',
    'q3a' => 'q2b_answer',
    'q3b' => 'q3a_answer',
    'q4a' => 'q3b_answer',
    'q4b' => 'q4a_answer',
    'q5a' => 'q4b_answer',
    'q5b' => 'q5a_answer',
];

$FIELD_ASSESSMENT_DATE = 'date_assessment';

/* ------------------------------------------------------------
 * MSISDN normalisation — SMS Works (E.164)
 * ------------------------------------------------------------ */
function normalise_msisdn_for_smsworks($raw){
    $d = preg_replace('/\D+/', '', $raw ?? '');

    // UK mobile: 07xxxxxxxxx → 447xxxxxxxxx
    if (strpos($d, '07') === 0) {
        return '44' . substr($d, 1);
    }

    // Already E.164
    if (strpos($d, '44') === 0) {
        return $d;
    }

    return $d;
}

/* ------------------------------------------------------------
 * send_and_record (supports AUX sends: reminders/help won't overwrite prov-id/status)
 * ------------------------------------------------------------ */
function send_and_record($qCode,$rid,$inst,$to,$text,$isAux=false){
    global $PROVIDER,$FIRETEXT_API_KEY,$SENDER_ID,$SMSW_JWT_RAW;
    global $FOLLOWUP_EVENT,$FOLLOWUP_REPEAT_INSTR,$REDCAP_API_TOKEN,$REDCAP_API_URL,$SMSW_FIELD_MAP;

    $provField = $SMSW_FIELD_MAP[$qCode]['prov']   ?? null;
    $statField = $SMSW_FIELD_MAP[$qCode]['status'] ?? null;

    $provId=''; $status=null;

    if ($PROVIDER==='firetext'){
        $x=null; 
        $clean=normalise_msisdn_for_firetext($to);
        /* FireText branch still OK to use current_sender_id() if you wish;
           leaving as-is here (uses the sender passed by caller). */
        $sender = function_exists('current_sender_id') ? current_sender_id() : ($GLOBALS['SENDER_ID'] ?? 'FireText');
        send_sms_firetext($FIRETEXT_API_KEY,$clean,$sender,$text,$x);
        $provId=$x??'';
    } else {
        // $digits=preg_replace('/\D+/','',$to);
        $digits = normalise_msisdn_for_smsworks($to);
        /* TINY PATCH (SMS Works only): use current_sender_id() */
        $sender = function_exists('current_sender_id') ? current_sender_id() : ($GLOBALS['SENDER_ID'] ?? 'SMSWorks');
        $resp=send_sms_smsworks($SMSW_JWT_RAW,$digits,$sender,$text /*, $deliveryReportUrl */);
        $provId=$resp['messageid']??''; $status=$resp['status']??null;
    }

    // Write-back only for non-aux (original) sends
    $u=[
        'record_id'                => $rid,
        'redcap_event_name'        => $FOLLOWUP_EVENT,
        'redcap_repeat_instrument' => $FOLLOWUP_REPEAT_INSTR,
        'redcap_repeat_instance'   => $inst
    ];
    if (!$isAux && $provField && $provId!=='')   $u[$provField]=$provId;
    if (!$isAux && $statField && $status!==null) $u[$statField]=$status;

    if (count($u)>4){
        logv(($isAux?'AUX ':'').'IMPORT-SEND row: ' . json_encode($u));
        $resp = redcap_import_records($REDCAP_API_TOKEN,$REDCAP_API_URL,[$u]);
        logv(($isAux?'AUX ':'').'IMPORT-SEND resp: ' . json_encode($resp));
    }

    return [$provId,$status];
}

/* ------------------------------------------------------------
 * HELP handler (?help=1&rid=&inst=&reason=help|invalid|parse_fail|no_mapping)
 *   - HELP: RL per record = HELP_RATE_LIMIT_MINUTES (fallback 60)
 *   - INVALID: at most once per calendar day per (record, instance)
 * ------------------------------------------------------------ */
if ($HELP_ENABLED && isset($_GET['help']) && $_GET['help']=='1') {
    $rid = (int)($_GET['rid'] ?? 0);
    $inst= (int)($_GET['inst'] ?? 0);
    $reason = $_GET['reason'] ?? '';
    $helpState = state_load($HELP_STATE_FILE);

    $nowYmd = (new DateTime('now'))->format('Y-m-d');

    $allow = true;
    if ($reason === 'help') {
        $last = help_last_ts($helpState, $rid);
        $cool = (int)$HELP_RL_MIN * 60;
        if ($rid>0 && $last !== null && (time() - $last) < $cool) {
            $allow = false;
            logv("HELP AUTOREPLY skipped by {$HELP_RL_MIN}‑min rate-limit for record {$rid}");
        }
    } elseif ($reason === 'invalid') {
        if ($rid>0 && $inst>0 && invalid_already_sent_today($helpState,$rid,$inst,$nowYmd)) {
            $allow = false;
            logv("HELP (invalid) skipped — already sent today for record {$rid} inst {$inst}");
        }
    }

    if ($allow && $rid>0) {
        try {
            // phone from baseline
            $bl = redcap_export_records($REDCAP_API_TOKEN,$REDCAP_API_URL, ['record_id',$FIELD_PHONE], "", [$BASELINE_EVENT]);
            $phone = null;
            foreach($bl as $r){ if((int)$r['record_id']===$rid){ $phone = $r[$FIELD_PHONE] ?? null; break; } }
            if ($phone){
                logv("HELP AUTOREPLY → record {$rid}, inst {$inst}, reason={$reason}");
                // send as AUX (do not persist provider-id/status fields)
                // we can reuse any qCode for mapping convenience; choose 'q1a'
                send_and_record('q1a', $rid, ($inst>0?$inst:1), $phone, $HELP_TEXT, true);
                // mark RL
                if ($reason === 'help') {
                    help_mark_now($helpState, $rid);
                } elseif ($reason === 'invalid' && $inst>0) {
                    invalid_mark_today($helpState,$rid,$inst,$nowYmd);
                }
                state_save($HELP_STATE_FILE, $helpState);
                echo "<p><b>HELP auto-reply sent</b></p>";
                return;
            } else {
                logv("HELP AUTOREPLY aborted: phone not found for record {$rid}");
            }
        } catch (Throwable $e){
            logv("HELP AUTOREPLY error: ".$e->getMessage());
        }
    }
    echo "<p><b>HELP handler completed.</b></p>";
    return;
}

/* ------------------------------------------------------------
 * Export baseline (phone + baseline)
 * ------------------------------------------------------------ */
logv("Exporting baseline rows (phone + baseline date)…");
$baseline = redcap_export_records(
    $REDCAP_API_TOKEN,
    $REDCAP_API_URL,
    ['record_id', $FIELD_PHONE, $FIELD_BASELINE_DATE, 'q1aa', 'compl_pref'],
    "",
    [$BASELINE_EVENT]
);
$phoneMap = [];
$baselineDate = [];
$baselineQ1aa = [];
$baselineComplPref = [];
foreach ($baseline as $r) {
    $rid = $r['record_id'] ?? null;
    if (!$rid) continue;

    if (!empty($r[$FIELD_PHONE])) {
        $phoneMap[$rid] = $r[$FIELD_PHONE];
    }

    if (!empty($r[$FIELD_BASELINE_DATE])) {
        $baselineDate[$rid] = $r[$FIELD_BASELINE_DATE];
    }

    if (isset($r['q1aa'])) {
        $baselineQ1aa[$rid] = trim((string)$r['q1aa']);
    }

    if (isset($r['compl_pref'])) {
        $baselineComplPref[$rid] = (string)$r['compl_pref'];
    }
}
logv("Baseline phone entries: ".count($phoneMap).", baseline dates: ".count($baselineDate));

/* ------------------------------------------------------------
 * Backfill date_baseline (Y-m-d) if missing
 * ------------------------------------------------------------ */
$setBaseRows=[];
foreach ($phoneMap as $rid=>$phone){
    if (empty($baselineDate[$rid])) {
        $todayYmd = date('Y-m-d');
        $setBaseRows[] = [
            'record_id'         => $rid,
            'redcap_event_name' => $BASELINE_EVENT,
            $FIELD_BASELINE_DATE=> $todayYmd
        ];
        logv("Setting date_baseline for record {$rid} → {$todayYmd}");
        $baselineDate[$rid] = $todayYmd;
    }
}
if ($setBaseRows) {
    $resp=redcap_import_records($REDCAP_API_TOKEN,$REDCAP_API_URL,$setBaseRows);
    logv('IMPORT-BASELINE resp: ' . json_encode($resp));
}

/* ------------------------------------------------------------
 * Export follow-up (include provider-id/status)
 * ------------------------------------------------------------ */
$needFields = [$FIELD_DAY_NUMBER,$FIELD_OPT_OUT,$FIELD_ASSESSMENT_DATE];
foreach ($SEQUENCE as $s){ $needFields[]=$s['q']; $needFields[]=$s['a']; }
if (!empty($SMSW_FIELD_MAP)){
    foreach ($SMSW_FIELD_MAP as $q=>$m){
        if (!empty($m['prov']))   $needFields[]=$m['prov'];
        if (!empty($m['status'])) $needFields[]=$m['status'];
    }
}
$allRows=redcap_export_records(
    $REDCAP_API_TOKEN,$REDCAP_API_URL,
    array_merge(['record_id'],$needFields),
    "",
    [$FOLLOWUP_EVENT]
);
logv("Follow-up rows returned: ".count($allRows));

$byRecord=[]; $hasInstance1=[];
foreach($allRows as $r){
    if(($r['redcap_repeat_instrument']??'')!==$FOLLOWUP_REPEAT_INSTR) continue;
    $rid=$r['record_id']??'';
    $inst=(int)($r['redcap_repeat_instance']??0);
    if($inst>=1){
        $byRecord[$rid][$inst]=$r;
        if($inst===1) $hasInstance1[$rid]=true;
    }
}

/* ------------------------------------------------------------
 * Create Day 1 where missing
 * ------------------------------------------------------------ */
$createRows=[];
foreach($baselineDate as $rid=>$baseRaw){
    if(empty($phoneMap[$rid])) continue;
    if(empty($hasInstance1[$rid])){
        $d1 = parse_baseline_date_dmy($baseRaw) ?: new DateTime('today');
        $d1Ymd = fmt_import_ymd($d1);
        $createRows[]=[
            'record_id'                => $rid,
            'redcap_event_name'        => $FOLLOWUP_EVENT,
            'redcap_repeat_instrument' => $FOLLOWUP_REPEAT_INSTR,
            'redcap_repeat_instance'   => 1,
            $FIELD_DAY_NUMBER          => '1',
            $FIELD_ASSESSMENT_DATE     => $d1Ymd
        ];
        logv("Prepared Day 1 for record {$rid} — {$FIELD_ASSESSMENT_DATE}={$d1Ymd}");
    }
}
if($createRows){
    logv("Creating ".count($createRows)." Day 1 instances…");
    $resp=redcap_import_records($REDCAP_API_TOKEN,$REDCAP_API_URL,$createRows);
    logv('IMPORT-DAY1 resp: ' . json_encode($resp));

    // Refresh
    $allRows=redcap_export_records(
        $REDCAP_API_TOKEN,$REDCAP_API_URL,
        array_merge(['record_id'],$needFields),
        "",
        [$FOLLOWUP_EVENT]
    );
    $byRecord=[];
    foreach($allRows as $r){
        if(($r['redcap_repeat_instrument']??'')!==$FOLLOWUP_REPEAT_INSTR) continue;
        $rid=$r['record_id']??'';
        $inst=(int)($r['redcap_repeat_instance']??0);
        if($inst>=1) $byRecord[$rid][$inst]=$r;
    }
}

/* ------------------------------------------------------------
 * Catch-up to today (<= MAX_DAYS), respecting opt-out
 * ------------------------------------------------------------ */
$createNext=[];
foreach($byRecord as $rid=>$insts){
    if(empty($baselineDate[$rid]) || empty($phoneMap[$rid])) continue;

    $todayDay=get_today_day_number($baselineDate[$rid]);
    if(!$todayDay) continue;

    $limit=min($todayDay,$MAX_DAYS);

    $stopAtOptOut=false;
    foreach($insts as $i=>$row){
        if(($row[$FIELD_OPT_OUT]??'')==='0'){ $stopAtOptOut=true; break; }
    }
    if($stopAtOptOut) continue;

    $baseDt=parse_baseline_date_dmy($baselineDate[$rid]) ?: new DateTime('today');

    for($i=1;$i<=$limit;$i++){
        if(isset($insts[$i])) continue;
        $dtClone = (clone $baseDt)->modify('+'.($i-1).' day');
        $ymd = fmt_import_ymd($dtClone);

        $createNext[]=[
            'record_id'                => $rid,
            'redcap_event_name'        => $FOLLOWUP_EVENT,
            'redcap_repeat_instrument' => $FOLLOWUP_REPEAT_INSTR,
            'redcap_repeat_instance'   => $i,
            $FIELD_DAY_NUMBER          => (string)$i,
            $FIELD_ASSESSMENT_DATE     => $ymd
        ];
        logv("Prepared Day {$i} for record {$rid} — {$FIELD_ASSESSMENT_DATE}={$ymd}");
    }
}
if($createNext){
    logv("Creating ".count($createNext)." catch-up instances…");
    $resp=redcap_import_records($REDCAP_API_TOKEN,$REDCAP_API_URL,$createNext);
    logv('IMPORT-CATCHUP resp: ' . json_encode($resp));

    // Refresh again
    $allRows=redcap_export_records(
        $REDCAP_API_TOKEN,$REDCAP_API_URL,
        array_merge(['record_id'],$needFields),
        "",
        [$FOLLOWUP_EVENT]
    );
    $byRecord=[];
    foreach($allRows as $r){
        if(($r['redcap_repeat_instrument']??'')!==$FOLLOWUP_REPEAT_INSTR) continue;
        $rid=$r['record_id']??'';
        $inst=(int)($r['redcap_repeat_instance']??0);
        if($inst>=1) $byRecord[$rid][$inst]=$r;
    }
}

/* ------------------------------------------------------------
 * Optional pruning
 * ------------------------------------------------------------ */
if($RUN_PRUNE){
    $deleted=0; $attempts=0;
    foreach($byRecord as $rid=>$insts){
        ksort($insts);
        foreach($insts as $inst=>$row){
            if($inst>$PRUNE_KEEP){
                $attempts++;
                $resp = redcap_post($GLOBALS['REDCAP_API_URL'],[
                    'token'=>$GLOBALS['REDCAP_API_TOKEN'],
                    'content'=>'record',
                    'action'=>'delete',
                    'records[0]'=>$rid,
                    'event'=>$GLOBALS['FOLLOWUP_EVENT'],
                    'instrument'=>$GLOBALS['FOLLOWUP_REPEAT_INSTR'],
                    'repeat_instance'=>(string)$inst,
                    'returnFormat'=>'json'
                ]);
                $deleted++;
                logv("🗑️ Deleted record {$rid} instance {$inst}");
            }
        }
    }
    logv("Pruning finished — attempted: $attempts, deleted: $deleted. Re-exporting…");
    // Refresh after prune
    $allRows=redcap_export_records(
        $REDCAP_API_TOKEN,$REDCAP_API_URL,
        array_merge(['record_id'],$needFields),
        "",
        [$FOLLOWUP_EVENT]
    );
    $byRecord=[];
    foreach($allRows as $r){
        if(($r['redcap_repeat_instrument']??'')!==$FOLLOWUP_REPEAT_INSTR) continue;
        $rid=$r['record_id']??'';
        $inst=(int)($r['redcap_repeat_instance']??0);
        if($inst>=1) $byRecord[$rid][$inst]=$r;
    }
}

/* ------------------------------------------------------------
 * AUTO‑HEAL q1a — only in configured window
 * ------------------------------------------------------------ */
$AH_checked = 0;
$AH_sent = 0;
$AH_deferred = 0;
$AH_skipped_optout = 0;
$AH_missing_today = 0;
$AH_not_needed = 0;

// **AUTO‑HEAL WINDOW CHECK (q1a)**
logv("=====================================================");
logv("**AUTO‑HEAL CHECK FOR q1a** — evaluating records: " . implode(', ', array_keys($phoneMap)));
logv("-----------------------------------------------------------------------------------------");

foreach ($byRecord as $rid => $insts) {
    if (empty($baselineDate[$rid]) || empty($phoneMap[$rid])) continue;

    $todayDay = get_today_day_number($baselineDate[$rid]);

    // ------------------------------------------------------------
    // BASELINE DAY GUARD — never send SMS on Day 0
    // ------------------------------------------------------------
    if ($todayDay === 0) {
        logv("Record {$rid}: baseline Day 0 — no SMS sent");
        continue;
    }

    if ($todayDay > $MAX_DAYS) {
        continue; // silently ignore records beyond study window
    }

    $AH_checked++;

    if (!isset($insts[$todayDay])) {
        $AH_missing_today++;
        logv("AUTO-HEAL: Record {$rid} — today's instance (Day {$todayDay}) missing.");
        continue;
    }

    $row = $insts[$todayDay];

    if (($row[$FIELD_OPT_OUT] ?? '') === '0') {
        $AH_skipped_optout++;
        continue;
    }

    $q1aText = trim((string)($baselineQ1aa[$rid] ?? ''));
    $q1aAns  = trim((string)($row['q1a_answer'] ?? ''));
    $provFieldQ1a = $SMSW_FIELD_MAP['q1a']['prov'] ?? null;
    $alreadyProv  = $provFieldQ1a ? trim((string)($row[$provFieldQ1a] ?? '')) : '';

    $needsQ1a = ($q1aText !== '' && $q1aAns === '' && $alreadyProv === '');
    if (!$needsQ1a) { $AH_not_needed++; continue; }

    if (!allow_auto_heal_now_global($HEAL_START,$HEAL_END)) { $AH_deferred++; logv("AUTO-HEAL: Record {$rid} Day {$todayDay} — outside window {$HEAL_START}:00–{$HEAL_END}:59"); continue; }
    if (!allow_q1a_now_global($Q1A_HOUR))                 { $AH_deferred++; logv("AUTO-HEAL: Record {$rid} Day {$todayDay} — before {$Q1A_HOUR}:00"); continue; }

    $to = $phoneMap[$rid];
    try {
        list($prov,$status) = send_and_record('q1a', $rid, $todayDay, $to, $q1aText, false);
        $AH_sent++;
        logv("AUTO‑HEAL: q1a sent for record {$rid} (Day {$todayDay}) — msgid={$prov}, status={$status}");
    } catch (Exception $e) {
        $AH_deferred++;
        logv("AUTO‑HEAL: ERROR sending q1a for record {$rid} — ".$e->getMessage());
    }
}
error_log("STEP 2: AUTO-HEAL completed");
/* ------------------------------------------------------------
 * Reminder & HELP states
 * ------------------------------------------------------------ */
$qState = state_load($STATE_FILE);
$helpState = state_load($HELP_STATE_FILE);
$reminderSentCount = 0;

// Refresh baseline once more to ensure survey data (q1aa / compl_pref) is available
$baseline = redcap_export_records(
    $REDCAP_API_TOKEN,
    $REDCAP_API_URL,
    ['record_id', $FIELD_PHONE, $FIELD_BASELINE_DATE, 'q1aa', 'compl_pref'],
    "",
    [$BASELINE_EVENT]
);

$baselineQ1aa = [];
$baselineComplPref = [];
foreach ($baseline as $r) {
    $rid = $r['record_id'] ?? null;
    if (!$rid) continue;

    if (isset($r['q1aa'])) {
        $baselineQ1aa[$rid] = trim((string)$r['q1aa']);
    }
    if (isset($r['compl_pref'])) {
        $baselineComplPref[$rid] = (string)$r['compl_pref'];
    }
}

logv("Baseline refreshed before SEND LOOP");
/* ------------------------------------------------------------
 * SEND LOOP (q1a + q1b..q5b) + window-aware reminders + “0”→opt-out
 * ------------------------------------------------------------ */
$sentCount=0;

foreach($byRecord as $rid=>$insts){
    if(empty($baselineDate[$rid]) || empty($phoneMap[$rid])) continue;

    ksort($insts);
    $todayDay=get_today_day_number($baselineDate[$rid]);
    $to=$phoneMap[$rid];

    // ------------------------------------------------------------
    // BASELINE DAY GUARD — never send SMS on Day 0
    // ------------------------------------------------------------
    if ($todayDay === 0) {
        logv("Record {$rid}: baseline Day 0 — no SMS sent");
        continue;
    }

    if ($todayDay > $MAX_DAYS) {
        continue; // silently ignore records beyond study window
    }

    foreach($insts as $inst=>$row){
        if($inst>$MAX_DAYS) continue;

        /* ---------- AUTO OPT-OUT if any answer == "0" ---------- */
        if (($row[$FIELD_OPT_OUT] ?? '') !== '0') {
            $zeroOpt = false;
            foreach ($SEQUENCE as $s) {
                $ansField = $s['a'];
                if (isset($row[$ansField]) && trim((string)$row[$ansField]) === '0') { $zeroOpt = true; break; }
            }
            if ($zeroOpt) {
                $upd = [[
                    'record_id'                => $rid,
                    'redcap_event_name'        => $FOLLOWUP_EVENT,
                    'redcap_repeat_instrument' => $FOLLOWUP_REPEAT_INSTR,
                    'redcap_repeat_instance'   => $inst,
                    $FIELD_OPT_OUT             => '0'
                ]];
                $r = redcap_import_records($REDCAP_API_TOKEN,$REDCAP_API_URL,$upd);
                logv("OPT-OUT (by answer=0): record {$rid} Day {$inst} — resp=".json_encode($r));
                continue; // stop this instance
            }
        }
        if(($row[$FIELD_OPT_OUT]??'')==='0') continue;

        $nextQ=find_next_unanswered_question($row,$SEQUENCE);
        if(!$nextQ) continue;

        /* ---------- REMINDER: only within window & age threshold ---------- */
        if ($REM_ENABLED && allow_reminder_window($REM_W_START,$REM_W_END)) {
            $provFieldNext=$SMSW_FIELD_MAP[$nextQ]['prov']??null;
            $answerFieldNext=null; foreach($SEQUENCE as $s){ if($s['q']===$nextQ){ $answerFieldNext=$s['a']; break; } }
            $ansVal = trim((string)($row[$answerFieldNext] ?? ''));
            $provVal= $provFieldNext ? trim((string)($row[$provFieldNext] ?? '')) : '';

            if ($ansVal==='' && $provVal!=='') {
                if (empty($qState[(string)$rid][(string)$inst][$nextQ]['first_sent_ts'])) {
                    q_state_mark_sent($qState,$rid,$inst,$nextQ);
                } else if (q_state_can_remind($qState,$rid,$inst,$nextQ,$REM_SECS,$REM_MAX)) {
                    $qText=trim((string)($row[$nextQ]??''));
                    if ($qText!=='') {
                        list($prov,$st)=send_and_record($nextQ,$rid,$inst,$to,$qText,true /* aux reminder */);
                        $reminderSentCount++;
                        logv("REMINDER sent for record {$rid} Day {$inst} {$nextQ} — msgid={$prov}");
                        q_state_mark_reminded($qState,$rid,$inst,$nextQ);
                    }
                }
            }
        }

        /* ---------- q1a: baseline kick-off + preference gate ---------- */
        if ($nextQ === 'q1a') {

            // Phone must exist
            if (empty($to)) {
                continue;
            }

            // Completion preference must be explicitly opted-in (baseline event)
            $cp = $baselineComplPref[$rid] ?? null;

            // Accept numeric 1 or string '1'
            if (!in_array((string)$cp, ['1'], true)) {
                continue;
            }

            // Only allow q1a for first instance
            if ($inst !== 1) {
                continue;
            }

            // Get q1a text from BASELINE event (q1aa)
            $q1aText = trim((string)($baselineQ1aa[$rid] ?? ''));

            $q1aAnswer = trim((string)($row['q1a_answer'] ?? ''));
            $provFieldQ1a = $SMSW_FIELD_MAP['q1a']['prov'] ?? null;
            $alreadyProv  = $provFieldQ1a ? trim((string)($row[$provFieldQ1a] ?? '')) : '';

            // Eligibility checks
            if ($q1aText === '' || $q1aAnswer !== '' || $alreadyProv !== '') {
                continue;
            }

            // Time guard
            if (!allow_q1a_now_global($Q1A_HOUR)) {
                continue;
            }

            // Send q1a
            list($prov,$st) = send_and_record('q1a', $rid, $inst, $to, $q1aText, false);
            q_state_mark_sent($qState, $rid, $inst, 'q1a');

            echo "✔ Sent q1a for record {$rid} (Day {$inst}) — msgid={$prov}<br>";
            $sentCount++;
            continue;
        }

        /* ---------- q1b..q5b strict gate ---------- */
        $prevAnsField = $PREV_ANSWER[$nextQ] ?? null;
        if($prevAnsField){
            $prevVal = $row[$prevAnsField] ?? '';
            if(!is_answered_1to10($prevVal)){
                logv("Record {$rid} Day {$inst} — {$nextQ} blocked: previous answer {$prevAnsField} missing/invalid");
                continue;
            }
        }

        $qText=trim((string)($row[$nextQ]??''));
        if($qText===''){
            logv("Record {$rid} Day {$inst}: {$nextQ} text blank; skipping");
            continue;
        }

        $provFieldNext=$SMSW_FIELD_MAP[$nextQ]['prov']??null;
        if($provFieldNext && trim((string)($row[$provFieldNext]??''))!==''){
            logv("Record {$rid} Day {$inst}: {$nextQ} already sent; skipping");
            continue;
        }

        list($prov,$status)=send_and_record($nextQ,$rid,$inst,$to,$qText,false);
        q_state_mark_sent($qState,$rid,$inst,$nextQ);
        echo "✔ Sent {$nextQ} for record {$rid} (Day {$inst}) — msgid={$prov}";
        if($status) echo " — status={$status}";
        echo "<br>";
        $sentCount++;
    }
}

/* ------------------------------------------------------------
 * Summaries & save states
 * ------------------------------------------------------------ */
logv("Reminders sent this run: {$reminderSentCount}");
state_save($STATE_FILE,$qState);
state_save($HELP_STATE_FILE,$helpState);

echo "<p><b>Total messages sent this run: {$sentCount}</b></p>";
echo "<p><b>Reminders sent this run: {$reminderSentCount}</b></p>";

error_log("STEP 4: END OF SCRIPT");