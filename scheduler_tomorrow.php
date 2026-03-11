<?php
/**
 * scheduler_tomorrow.php
 *
 * Pre-create tomorrow’s instance for each record (<= MAX_DAYS)
 * and set date_assessment to baseline+(N-1) days (Y-m-d).
 * Never sends SMS. Intended for a nightly cron at ~00:05.
 * - Logs to logs/scheduler_tomorrow.log (auto-creates folder)
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

$LOG_FILE = rtrim(LOG_DIR, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'scheduler_tomorrow.log';
$MAX_LOG_BYTES = 5 * 1024 * 1024;
if (is_file($LOG_FILE) && filesize($LOG_FILE) > $MAX_LOG_BYTES) {
    @rename($LOG_FILE, $LOG_FILE . '.' . date('Ymd_His'));
}

$VERBOSE = isset($_GET['verbose']) && $_GET['verbose'] !== '0';

function log_to_file($s){ global $LOG_FILE; @file_put_contents($LOG_FILE,'['.date('Y-m-d H:i:s').'] '.$s.PHP_EOL, FILE_APPEND|LOCK_EX); }
function logv($m){ global $VERBOSE; log_to_file($m); if ($VERBOSE) echo "[",date('H:i:s'),"] ",htmlspecialchars($m),"<br>\n"; }

log_to_file(str_repeat('-', 60));
log_to_file('Run started: scheduler_tomorrow.php time=' . date('c'));

/* ---------------------------------------------------------
 * Config guards / defaults
 * --------------------------------------------------------- */
if (!isset($FIELD_ASSESSMENT_DATE) || !$FIELD_ASSESSMENT_DATE) {
    $FIELD_ASSESSMENT_DATE = 'date_assessment';
}
if (!isset($MAX_DAYS) || !$MAX_DAYS) {
    $MAX_DAYS = 999; // sensible default if not set in config.php
}

/* ---------------------------------------------------------
 * REDCap helpers
 * --------------------------------------------------------- */
function rc_post($url,$data){
    $ch=curl_init();
    curl_setopt_array($ch,[
        CURLOPT_URL=>$url, CURLOPT_POST=>true, CURLOPT_RETURNTRANSFER=>true,
        CURLOPT_SSL_VERIFYPEER=>true, CURLOPT_POSTFIELDS=>$data
    ]);
    $out=curl_exec($ch); $code=curl_getinfo($ch,CURLINFO_HTTP_CODE); $err=curl_error($ch); curl_close($ch);
    if($code!==200) throw new RuntimeException("REDCap API error ($code): $out :: $err");
    $j=json_decode($out,true);
    return $j ?? $out;
}
function rc_export($fields,$events=[],$filterLogic=''){
    $p=['token'=>$GLOBALS['REDCAP_API_TOKEN'],'content'=>'record','format'=>'json','type'=>'flat','filterLogic'=>$filterLogic];
    // Guard against NULL/empty fields
    $i=0; foreach($fields as $f){ if($f){ $p["fields[$i]"]=$f; $i++; } }
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
 * Date helpers (API import must be Y-m-d)
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
function get_today_day_number($baselineRaw){
    $base=parse_baseline_date_dmy($baselineRaw); if(!$base) return null;
    $today=new DateTime('today'); $diff=(int)$base->diff($today)->format('%a'); return $diff+1;
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
logv("Found ".count($phoneMap)." phones; ".count($baselineDate)." with baseline dates");

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
 * Export follow-up rows (flat export auto-includes repeat meta)
 * --------------------------------------------------------- */
$needFields = ['record_id', $FIELD_DAY_NUMBER, $FIELD_OPT_OUT, $FIELD_ASSESSMENT_DATE];
$all = rc_export($needFields, [$FOLLOWUP_EVENT]);

// Build existing instances per record
$byRecord=[];
foreach($all as $r){
    if(($r['redcap_repeat_instrument']??'')!==$FOLLOWUP_REPEAT_INSTR) continue;
    $rid=$r['record_id']??''; $inst=(int)($r['redcap_repeat_instance']??0);
    if($inst>=1) $byRecord[$rid][$inst]=$r;
}

/* ---------------------------------------------------------
 * Compute tomorrow and create missing instance
 * --------------------------------------------------------- */
$create=[];
$createdCount=0;

foreach($phoneMap as $rid=>$phone){
    if (empty($baselineDate[$rid])) continue;
    $todayDay = get_today_day_number($baselineDate[$rid]); if(!$todayDay) continue;
    $tomorrowDay = $todayDay + 1;
    if ($tomorrowDay > $MAX_DAYS) continue;

    // Stop if ANY instance is opted out
    $insts = $byRecord[$rid] ?? [];
    $opted = false; foreach($insts as $i=>$row){ if(($row[$FIELD_OPT_OUT]??'')==='0'){ $opted=true; break; } }
    if ($opted) continue;

    // Already exists?
    if (!empty($insts[$tomorrowDay])) { logv("Record $rid — Day $tomorrowDay already exists; skip"); continue; }

    // date_assessment = baseline + (tomorrowDay-1) in Y-m-d
    $baseDt = parse_baseline_date_dmy($baselineDate[$rid]) ?: new DateTime('today');
    $dt = (clone $baseDt)->modify('+'.($tomorrowDay-1).' day');
    $ymd = $dt->format('Y-m-d');

    $create[] = [
        'record_id'                => $rid,
        'redcap_event_name'        => $FOLLOWUP_EVENT,
        'redcap_repeat_instrument' => $FOLLOWUP_REPEAT_INSTR,
        'redcap_repeat_instance'   => $tomorrowDay,
        $FIELD_DAY_NUMBER          => (string)$tomorrowDay,
        $FIELD_ASSESSMENT_DATE     => $ymd
    ];
    logv("Prepared record $rid — Day $tomorrowDay with $FIELD_ASSESSMENT_DATE=$ymd");
    $createdCount++;
}

if ($create){
    $resp=rc_import($create);
    logv("Created $createdCount tomorrow instance(s); resp=" . json_encode($resp));
    if ($VERBOSE) echo "<p><b>Created $createdCount tomorrow instance(s)</b></p>";
} else {
    logv("No instances needed for tomorrow");
    if ($VERBOSE) echo "<p><b>No instances needed for tomorrow.</b></p>";
}