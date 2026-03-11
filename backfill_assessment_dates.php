<?php
/**
 * backfill_assessment_dates.php
 *
 * Fills missing date_assessment on existing instances using baseline+(fup_day_number-1) days (Y-m-d).
 * Does not send SMS. Safe to run on demand or periodically.
 * - Logs to logs/backfill_assessment_dates.log (auto-creates folder)
 * - Use ?verbose=1 to echo details to browser/CLI
 */

require_once __DIR__ . '/config.php';
date_default_timezone_set($TIMEZONE);

/* ---------------------------------------------------------
 * Log directory & file
 * --------------------------------------------------------- */
if (!defined('LOG_DIR')) {
    $try = realpath(__DIR__ . '/../logs');
    if (!$try) $try = __DIR__ . '/logs';
    define('LOG_DIR', $try);
}
if (!is_dir(LOG_DIR)) { @mkdir(LOG_DIR, 0775, true); }

$LOG_FILE = rtrim(LOG_DIR, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'backfill_assessment_dates.log';
$MAX_LOG_BYTES = 5 * 1024 * 1024;
if (is_file($LOG_FILE) && filesize($LOG_FILE) > $MAX_LOG_BYTES) {
    @rename($LOG_FILE, $LOG_FILE . '.' . date('Ymd_His'));
}

$VERBOSE = isset($_GET['verbose']) && $_GET['verbose'] !== '0';

function log_to_file($s){ global $LOG_FILE; @file_put_contents($LOG_FILE,'['.date('Y-m-d H:i:s').'] '.$s.PHP_EOL, FILE_APPEND|LOCK_EX); }
function logv($m){ global $VERBOSE; log_to_file($m); if ($VERBOSE) echo "[",date('H:i:s'),"] ",htmlspecialchars($m),"<br>\n"; }

log_to_file(str_repeat('-', 60));
log_to_file('Run started: backfill_assessment_dates.php time=' . date('c'));

/* ---------------------------------------------------------
 * Config guards / defaults
 * --------------------------------------------------------- */
if (!isset($FIELD_ASSESSMENT_DATE) || !$FIELD_ASSESSMENT_DATE) {
    $FIELD_ASSESSMENT_DATE = 'date_assessment';
}

/* ---------------------------------------------------------
 * REDCap helpers
 * --------------------------------------------------------- */
function rc_post($url,$data){
    $ch=curl_init();
    curl_setopt_array($ch,[CURLOPT_URL=>$url, CURLOPT_POST=>true, CURLOPT_RETURNTRANSFER=>true, CURLOPT_SSL_VERIFYPEER=>true, CURLOPT_POSTFIELDS=>$data]);
    $out=curl_exec($ch); $code=curl_getinfo($ch,CURLINFO_HTTP_CODE); $err=curl_error($ch); curl_close($ch);
    if($code!==200) throw new RuntimeException("REDCap API error ($code): $out :: $err");
    $j=json_decode($out,true); return $j ?? $out;
}
function rc_export($fields,$events=[],$filterLogic=''){
    $p=['token'=>$GLOBALS['REDCAP_API_TOKEN'],'content'=>'record','format'=>'json','type'=>'flat','filterLogic'=>$filterLogic];
    $i=0; foreach($fields as $f){ if($f){ $p["fields[$i]"]=$f; $i++; } } // guard against NULL/empty field names
    foreach($events as $j=>$e) $p["events[$j]"]=$e;
    return rc_post($GLOBALS['REDCAP_API_URL'],$p);
}
function rc_import($rows){
    return rc_post($GLOBALS['REDCAP_API_URL'],[
        'token'=>$GLOBALS['REDCAP_API_TOKEN'],'content'=>'record','format'=>'json','type'=>'flat',
        'overwriteBehavior'=>'normal','data'=>json_encode($rows),'returnContent'=>'ids','returnFormat'=>'json'
    ]);
}

/* ---------------------------------------------------------
 * Date helpers
 * --------------------------------------------------------- */
function parse_baseline_date_dmy($raw){
    if(!$raw) return null; $raw=trim((string)$raw); if($raw==='') return null;
    $norm=preg_replace('/[\/\.]/','-',$raw);
    foreach(['!d-m-Y','!j-n-Y','!Y-m-d'] as $fmt){
        $dt=DateTime::createFromFormat($fmt,$norm);
        if($dt && $dt->format('Y')>=1900) return $dt;
    }
    return null;
}

/* ---------------------------------------------------------
 * Export baseline
 * --------------------------------------------------------- */
logv("Exporting baseline rows (phone + date_baseline) …");
$baseline = rc_export(['record_id',$FIELD_PHONE,$FIELD_BASELINE_DATE], [$BASELINE_EVENT]);

$phoneMap=[]; $baselineDate=[];
foreach($baseline as $r){
    $rid=$r['record_id']??null; if(!$rid) continue;
    if(!empty($r[$FIELD_PHONE]))         $phoneMap[$rid]=$r[$FIELD_PHONE];
    if(!empty($r[$FIELD_BASELINE_DATE])) $baselineDate[$rid]=$r[$FIELD_BASELINE_DATE];
}
logv("Phones=".count($phoneMap).", baselines=".count($baselineDate));

/* Backfill date_baseline if empty (Y-m-d) */
$baseSet=[];
foreach ($phoneMap as $rid=>$phone){
    if (empty($baselineDate[$rid])){
        $todayYmd=date('Y-m-d');
        $baseSet[]=['record_id'=>$rid,'redcap_event_name'=>$BASELINE_EVENT,$FIELD_BASELINE_DATE=>$todayYmd];
        $baselineDate[$rid]=$todayYmd;
        logv("Set date_baseline for record $rid → $todayYmd");
    }
}
if($baseSet){ $resp=rc_import($baseSet); logv('IMPORT-BASELINE resp: ' . json_encode($resp)); }

/* ---------------------------------------------------------
 * Export follow-up (need day number + date_assessment)
 * --------------------------------------------------------- */
$rows = rc_export(['record_id',$FIELD_DAY_NUMBER,$FIELD_ASSESSMENT_DATE], [$FOLLOWUP_EVENT]);

$updates=[];
$fixCount=0;
foreach($rows as $r){
    if(($r['redcap_repeat_instrument']??'')!==$FOLLOWUP_REPEAT_INSTR) continue;
    $rid=$r['record_id']??null; $inst=(int)($r['redcap_repeat_instance']??0);
    if(!$rid || $inst<1) continue;

    $dayNum = (int)($r[$FIELD_DAY_NUMBER] ?? 0);
    $assess = trim((string)($r[$FIELD_ASSESSMENT_DATE] ?? ''));
    if ($assess !== '' || $dayNum < 1) continue;

    $baseRaw = $baselineDate[$rid] ?? null; if(!$baseRaw) continue;
    $baseDt  = parse_baseline_date_dmy($baseRaw) ?: new DateTime('today');
    $dt      = (clone $baseDt)->modify('+'.($dayNum-1).' day');
    $ymd     = $dt->format('Y-m-d'); // API import format

    $updates[]=[
        'record_id'                => $rid,
        'redcap_event_name'        => $FOLLOWUP_EVENT,
        'redcap_repeat_instrument' => $FOLLOWUP_REPEAT_INSTR,
        'redcap_repeat_instance'   => $inst,
        $FIELD_ASSESSMENT_DATE     => $ymd
    ];
    $fixCount++;
    logv("Backfilled record $rid Day $dayNum → $FIELD_ASSESSMENT_DATE=$ymd");
}

if ($updates){
    $resp=rc_import($updates);
    logv("Backfilled $fixCount instance date(s); resp=" . json_encode($resp));
    if ($VERBOSE) echo "<p><b>Backfilled $fixCount instance date(s)</b></p>";
} else {
    logv("No date_assessment backfill needed");
    if ($VERBOSE) echo "<p><b>No date_assessment backfill needed.</b></p>";
}