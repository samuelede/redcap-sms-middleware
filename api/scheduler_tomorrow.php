<?php
/**
 * scheduler_tomorrow.php
 *
 * Pre-create the NEXT follow-up instance for each record (<= MAX_DAYS).
 * Instance numbers are SEQUENTIAL (never calendar-based).
 * date_assessment = baseline date + instance number (days), Y-m-d.
 *
 * Intended for nightly cron (~00:05).
 */

require_once __DIR__ . '/config.php';
date_default_timezone_set($TIMEZONE);

/* ---------------------------------------------------------
 * Logging
 * --------------------------------------------------------- */
if (!defined('LOG_DIR')) {
    $try = realpath(__DIR__ . '/../logs');
    if (!$try) $try = __DIR__ . '/logs';
    define('LOG_DIR', $try);
}
if (!is_dir(LOG_DIR)) @mkdir(LOG_DIR, 0775, true);

$LOG_FILE = LOG_DIR . '/scheduler_tomorrow.log';
$VERBOSE = isset($_GET['verbose']) && $_GET['verbose'] !== '0';

function logv($m){
    global $LOG_FILE, $VERBOSE;
    @file_put_contents($LOG_FILE, '['.date('Y-m-d H:i:s')."] $m\n", FILE_APPEND|LOCK_EX);
    if ($VERBOSE) echo "[",date('H:i:s'),"] ",htmlspecialchars($m),"<br>\n";
}

logv(str_repeat('-',60));
logv("Run started");

/* ---------------------------------------------------------
 * Defaults
 * --------------------------------------------------------- */
if (empty($FIELD_ASSESSMENT_DATE)) $FIELD_ASSESSMENT_DATE = 'date_assessment';
if (empty($MAX_DAYS)) $MAX_DAYS = 999;

/* ---------------------------------------------------------
 * REDCap helpers
 * --------------------------------------------------------- */
function rc_post($data){
    $ch = curl_init();
    curl_setopt_array($ch,[
        CURLOPT_URL=>$GLOBALS['REDCAP_API_URL'],
        CURLOPT_POST=>true,
        CURLOPT_RETURNTRANSFER=>true,
        CURLOPT_SSL_VERIFYPEER=>true,
        CURLOPT_POSTFIELDS=>$data
    ]);
    $out=curl_exec($ch);
    $code=curl_getinfo($ch,CURLINFO_HTTP_CODE);
    curl_close($ch);
    if($code!==200) throw new RuntimeException($out);
    return json_decode($out,true);
}

function rc_export($fields,$events=[]){
    $p=['token'=>$GLOBALS['REDCAP_API_TOKEN'],'content'=>'record','format'=>'json','type'=>'flat'];
    $i=0; foreach($fields as $f){ if($f){ $p["fields[$i]"]=$f; $i++; } }
    foreach($events as $j=>$e) $p["events[$j]"]=$e;
    return rc_post($p);
}

function rc_import($rows){
    if(!$rows) return;
    rc_post([
        'token'=>$GLOBALS['REDCAP_API_TOKEN'],
        'content'=>'record',
        'format'=>'json',
        'type'=>'flat',
        'overwriteBehavior'=>'normal',
        'data'=>json_encode($rows)
    ]);
}

/* ---------------------------------------------------------
 * Date helpers
 * --------------------------------------------------------- */
function parse_baseline_date($raw){
    if(!$raw) return null;
    $raw=preg_replace('/[\/\.]/','-',trim((string)$raw));
    foreach(['!d-m-Y','!j-n-Y','!Y-m-d'] as $fmt){
        $dt=DateTime::createFromFormat($fmt,$raw);
        if($dt) return $dt;
    }
    return null;
}

/* ---------------------------------------------------------
 * Export baseline
 * --------------------------------------------------------- */
logv("Exporting baseline rows (phone + date_baseline) …");
$baseline = rc_export(['record_id',$FIELD_PHONE,$FIELD_BASELINE_DATE], [$BASELINE_EVENT]);

$baselineDate=[];
$phoneMap=[];

foreach($baseline as $r){
    $rid=$r['record_id']??null;
    if(!$rid) continue;
    if(!empty($r[$FIELD_PHONE]))         $phoneMap[$rid]=$r[$FIELD_PHONE];
    if(!empty($r[$FIELD_BASELINE_DATE])) $baselineDate[$rid]=$r[$FIELD_BASELINE_DATE];
}

logv("Found ".count($phoneMap)." phones; ".count($baselineDate)." with baseline dates");

/* ---------------------------------------------------------
 * Export follow-up instances
 * --------------------------------------------------------- */
$followup = rc_export(
    ['record_id',$FIELD_DAY_NUMBER,$FIELD_OPT_OUT,$FIELD_ASSESSMENT_DATE],
    [$FOLLOWUP_EVENT]
);

// Build instances per record
$byRecord=[];
foreach($followup as $r){
    if(($r['redcap_repeat_instrument']??'')!==$FOLLOWUP_REPEAT_INSTR) continue;
    $rid=$r['record_id']??null;
    $inst=(int)($r['redcap_repeat_instance']??0);
    if($rid && $inst>=1){
        $byRecord[$rid][$inst]=$r;
    }
}

/* ---------------------------------------------------------
 * Create next instance per record
 * --------------------------------------------------------- */
$create=[];
$createdCount=0;

foreach($phoneMap as $rid=>$phone){

    if(empty($baselineDate[$rid])) continue;

    $insts = $byRecord[$rid] ?? [];

    // Skip if opted out in ANY instance
    $opted=false;
    foreach($insts as $row){
        if(($row[$FIELD_OPT_OUT]??'')==='0'){ $opted=true; break; }
    }
    if($opted) continue;

    // Determine next instance safely
    if($insts){
        $nextInst = max(array_keys($insts)) + 1;
    } else {
        $nextInst = 1;
    }

    if($nextInst > $MAX_DAYS) continue;

    // Already exists (paranoia guard)
    if(isset($insts[$nextInst])){
        logv("Record $rid — instance $nextInst already exists; skip");
        continue;
    }

    // Compute date_assessment = baseline + instance days
    $baseDt = parse_baseline_date($baselineDate[$rid]);
    if(!$baseDt) continue;

    $dt = (clone $baseDt)->modify("+{$nextInst} day");
    $ymd = $dt->format('Y-m-d');

    $create[]=[
        'record_id'=>$rid,
        'redcap_event_name'=>$FOLLOWUP_EVENT,
        'redcap_repeat_instrument'=>$FOLLOWUP_REPEAT_INSTR,
        'redcap_repeat_instance'=>$nextInst,
        $FIELD_DAY_NUMBER=>(string)$nextInst,
        $FIELD_ASSESSMENT_DATE=>$ymd
    ];

    logv("Prepared record $rid — Day $nextInst with $FIELD_ASSESSMENT_DATE=$ymd");
    $createdCount++;
}

if($create){
    rc_import($create);
    logv("Created $createdCount tomorrow instance(s)");
} else {
    logv("No instances needed for tomorrow");
}

if($VERBOSE) echo "<p><b>Created $createdCount instance(s)</b></p>";