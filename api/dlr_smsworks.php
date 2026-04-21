<?php
/**
 * dlr_smsworks.php
 *
 * Delivery Receipt (DLR) webhook for The SMS Works.
 *
 * What it does:
 *  - Accepts POST JSON from SMS Works DLR webhooks.
 *  - Extracts messageid + status.
 *  - Finds the REDCap follow-up repeating instance with a matching provider-id field.
 *  - Updates the corresponding "sms_sent_status_qX" field for that question.
 *
 * Requirements:
 *  - config.php must define: $REDCAP_API_URL, $REDCAP_API_TOKEN
 *                            $FOLLOWUP_EVENT, $FOLLOWUP_REPEAT_INSTR
 *                            $SMSW_FIELD_MAP (q1a..q5b => ['prov'=>..., 'status'=>...])
 *  - Configure SMS Works to call this URL as your Delivery Report URL:
 *      * Globally on your account page, or
 *      * Per message via 'deliveryreporturl' when you call /v1/message/send
 *    (Both are supported by The SMS Works API.)  // Docs mention JWT + message send + deliveryreporturl
 *
 * Security (optional, recommended):
 *  - Set an env var SMSW_DLR_SHARED_SECRET and require ?secret=<value> on the URL.
 * * IMPORTANT:
 * This endpoint handles DELIVERY REPORTS ONLY.
 * It must NEVER be used for inbound SMS replies.
 *
 * Inbound replies are handled exclusively by send_inbound.php
 */

require_once __DIR__ . '/config.php';
date_default_timezone_set($TIMEZONE);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo "Method Not Allowed";
    exit;
}

if (empty($payload['messageid'])) {
    error_log("DLR WARNING: payload missing messageid: " . json_encode($payload));
}

/* ------------ Optional shared-secret check ------------- */
$shared = getenv('SMSW_DLR_SHARED_SECRET');
if ($shared) {
    $supplied = $_GET['secret'] ?? $_POST['secret'] ?? '';
    if (!hash_equals($shared, $supplied)) {
        http_response_code(403);
        echo "forbidden";
        exit;
    }
}

/* ------------ Minimal REDCap helpers ------------------- */
function rc_post($url,$data){
    $ch=curl_init();
    curl_setopt_array($ch,[
        CURLOPT_URL=>$url,
        CURLOPT_POST=>true,
        CURLOPT_RETURNTRANSFER=>true,
        CURLOPT_SSL_VERIFYPEER=>true,
        CURLOPT_POSTFIELDS=>$data
    ]);
    $out=curl_exec($ch);
    $code=curl_getinfo($ch,CURLINFO_HTTP_CODE);
    $err=curl_error($ch);
    curl_close($ch);
    if ($code!==200) throw new RuntimeException("REDCap API error ($code): $out :: $err");
    $json=json_decode($out,true);
    return $json ?? $out;
}

function rc_export($fields,$filterLogic){
    $p=[
        'token'=>$GLOBALS['REDCAP_API_TOKEN'],
        'content'=>'record',
        'format'=>'json',
        'type'=>'flat',
        'filterLogic'=>$filterLogic
    ];
    foreach($fields as $i=>$f) $p["fields[$i]"]=$f;
    $p["events[0]"] = $GLOBALS['FOLLOWUP_EVENT'];
    return rc_post($GLOBALS['REDCAP_API_URL'],$p);
}

function rc_import($rows){
    return rc_post($GLOBALS['REDCAP_API_URL'],[
        'token'=>$GLOBALS['REDCAP_API_TOKEN'],
        'content'=>'record',
        'format'=>'json',
        'type'=>'flat',
        'overwriteBehavior'=>'normal',
        'data'=>json_encode($rows),
        'returnContent'=>'ids',
        'returnFormat'=>'json'
    ]);
}

/* ------------ Parse inbound JSON ----------------------- */
$raw = file_get_contents('php://input');
$payload = json_decode($raw, true);

// Fallback if SMS Works posts form-encoded (unlikely)
if (!$payload && !empty($_POST)) $payload = $_POST;

// Attempt to extract fields with defensive parsing.
$messageId = $payload['messageid'] ?? $payload['id'] ?? $payload['MessageId'] ?? '';
$statusRaw = $payload['status']    ?? $payload['delivery_status'] ?? $payload['dlrstatus'] ?? $payload['Status'] ?? '';

if (!$messageId) {
    http_response_code(400);
    echo "missing messageid";
    exit;
}
$status = strtoupper(trim((string)$statusRaw));
if ($status === '') $status = 'UNKNOWN';

/**
 * At this point we have:
 *  - $messageId: provider message id (e.g., "4730828681947950908370")
 *  - $status: delivery status ("SENT" | "DELIVERED" | "FAILED" | ... )
 *
 * The SMS Works API supports posting delivery reports either via a global setting or
 * per-message 'deliveryreporturl'. (See their API docs: Messages -> Send.)  // CITED in the message below
 */

/* ------------ Build a filter to find matching instance -- */
/**
 * We'll search for any row in the follow-up event where any provider-id field equals $messageId.
 * Using $SMSW_FIELD_MAP['qX']['prov'] for q1a..q5b, build an OR filter:
 *   [sms_prov_msgid_q1a] = 'ID' OR [sms_prov_msgid_q1b] = 'ID' OR ...
 */
$provFields = [];
foreach ($SMSW_FIELD_MAP as $qCode => $map) {
    if (!empty($map['prov'])) $provFields[] = $map['prov'];
}
if (!$provFields) {
    http_response_code(500);
    echo "no provider-id fields configured";
    exit;
}

$parts = [];
foreach ($provFields as $f) {
    // REDCap filterLogic uses square-bracketed variable names
    $parts[] = "([$f] = '" . str_replace("'", "''", $messageId) . "')";
}
$filter = implode(' OR ', $parts);

/* ------------ Export matching rows --------------------- */
$fieldsToFetch = array_merge(['record_id'], $provFields);
foreach ($SMSW_FIELD_MAP as $qCode=>$map) {
    if (!empty($map['status'])) $fieldsToFetch[] = $map['status']; // we'll overwrite status for the matched q only
}

$rows = rc_export($fieldsToFetch, $filter);

/* ------------ No matches? -------------------------------- */
if (!$rows || !is_array($rows) || count($rows) < 1) {
    // Could be a legitimate race: DLR before our writeback. Log 'OK' to avoid replays.
    http_response_code(200);
    echo "OK (no match)";
    exit;
}

/* ------------ For each matched row, figure out which q ---- */
$updates = [];
foreach ($rows as $r) {
    if (($r['redcap_repeat_instrument'] ?? '') !== $FOLLOWUP_REPEAT_INSTR) continue;

    $rid   = $r['record_id'] ?? null;
    $inst  = (int)($r['redcap_repeat_instance'] ?? 0);
    if (!$rid || $inst < 1) continue;

    // Identify which provider field matched
    $matchedQ = null;
    $matchedStatusField = null;

    foreach ($SMSW_FIELD_MAP as $qCode=>$map) {
        $provF = $map['prov']   ?? null;
        $statF = $map['status'] ?? null;
        if ($provF && isset($r[$provF]) && (string)$r[$provF] === (string)$messageId) {
            $matchedQ = $qCode;
            $matchedStatusField = $statF;
            break;
        }
    }

    if (!$matchedQ) continue;            // Shouldn't happen due to filter
    if (!$matchedStatusField) continue;  // If status field is null for that q, we skip

    $updates[] = [
        'record_id'                => $rid,
        'redcap_event_name'        => $FOLLOWUP_EVENT,
        'redcap_repeat_instrument' => $FOLLOWUP_REPEAT_INSTR,
        'redcap_repeat_instance'   => $inst,
        $matchedStatusField        => $status
    ];
}

/* ------------ Write updates ------------------------------ */
if ($updates) {
    rc_import($updates);
}

http_response_code(200);
echo "OK";