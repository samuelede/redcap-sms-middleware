<?php
/**
 * config.php — centralised configuration for REDCap SMS module
 * NO SECRETS ARE STORED HERE.
 * All secrets are loaded from /secure/secrets.php (git-ignored).
 */

/* ───────────────────────────────────────────────
 * Load Secrets (API Keys, tokens, VMNs, URLs)
 * ─────────────────────────────────────────────── */
$secretFile = __DIR__ . '/../secure/secrets.php';
if (!file_exists($secretFile)) {
    die("FATAL ERROR: Missing secure/secrets.php. Create it using the template.");
}
require_once $secretFile;

/* ───────────────────────────────────────────────
 * Global: Logs
 * ─────────────────────────────────────────────── */
define('LOG_DIR', __DIR__ . '/../logs');

/* ───────────────────────────────────────────────
 * REDCap Credentials (NOW SECRET)
 * ─────────────────────────────────────────────── */
$REDCAP_API_URL   = REDCAP_API_URL_SECRET;
$REDCAP_API_TOKEN = REDCAP_API_TOKEN_SECRET;

/* ───────────────────────────────────────────────
 * Provider selection
 * ─────────────────────────────────────────────── */
$PROVIDER = getenv('SMS_PROVIDER') ?: 'smsworks';  // 'smsworks' | 'firetext'

/* ───────────────────────────────────────────────
 * FireText (API key now secret)
 * ─────────────────────────────────────────────── */
$FIRETEXT_API_KEY = FIRETEXT_API_KEY_SECRET;

/* ───────────────────────────────────────────────
 * SMS Works authentication (JWT)
 * ─────────────────────────────────────────────── */

$SMSW_API_KEY    = SMSW_API_KEY_SECRET;
$SMSW_API_SECRET = SMSW_API_SECRET_SECRET;

// 1) Use static JWT if defined
$SMSW_JWT_RAW = defined('SMSW_STATIC_JWT_SECRET') && SMSW_STATIC_JWT_SECRET
    ? SMSW_STATIC_JWT_SECRET
    : null;

// 2) Generate JWT if needed
if ($PROVIDER === 'smsworks' && empty($SMSW_JWT_RAW)) {

    function smsworks_generate_jwt($key, $secret, $opts = []) {
        $endpoint = "https://api.thesmsworks.co.uk/v1/auth/token";
        $payload  = json_encode(["key" => $key, "secret" => $secret]);

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $endpoint,
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ["Content-Type: application/json"],
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_TIMEOUT        => $opts['timeout'] ?? 20,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        if (!empty($opts['ca']) && is_file($opts['ca'])) {
            curl_setopt($ch, CURLOPT_CAINFO, $opts['ca']);
        }
        if (!empty($opts['proxy'])) {
            curl_setopt($ch, CURLOPT_PROXY, $opts['proxy']);
        }

        $response = curl_exec($ch);
        $code     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $cerr     = curl_error($ch);
        $cerrno   = curl_errno($ch);
        curl_close($ch);

        if ($code !== 200) {
            $msg = "SMS Works auth/token failed (HTTP $code)";
            if ($cerrno || $cerr) $msg .= " | curl[$cerrno]: $cerr";
            if ($response)        $msg .= " | resp: $response";
            throw new RuntimeException($msg);
        }

        $data = json_decode($response, true);
        if (!isset($data['token'])) {
            throw new RuntimeException("SMS Works auth token response missing 'token'");
        }
        return $data['token'];
    }

    try {
        $SMSW_JWT_RAW = 'JWT ' . smsworks_generate_jwt($SMSW_API_KEY, $SMSW_API_SECRET, [
            // Optional CA bundle or proxy:
            // 'ca'    => CA_CERT_PATH_SECRET,
            // 'proxy' => PROXY_HTTP_SECRET,
            'timeout' => 25,
        ]);
    } catch (Throwable $e) {
        die("SMS Works JWT generation error: " . $e->getMessage());
    }
}

/* Normalize JWT */
if (!empty($SMSW_JWT_RAW) && stripos($SMSW_JWT_RAW, 'JWT ') !== 0) {
    $SMSW_JWT_RAW = 'JWT ' . trim($SMSW_JWT_RAW);
}

/* ───────────────────────────────────────────────
 * Sender IDs & Reply Numbers (VMNs) — Now safe
 * ─────────────────────────────────────────────── */

$SENDER_ID_SMSWORKS = SMSW_VMNS_SENDER_SECRET;   // numeric VMN
$SENDER_ID_FIRETEXT = FIRETEXT_VMNS_SENDER_SECRET;

$SMSW_REPLY_NUMBER     = SMSW_REPLY_NUMBER_SECRET;
$FIRETEXT_REPLY_NUMBER = FIRETEXT_REPLY_NUMBER_SECRET;

if (!function_exists('current_sender_id')) {
    function current_sender_id() {
        global $PROVIDER, $SENDER_ID_SMSWORKS, $SENDER_ID_FIRETEXT;
        return $PROVIDER === 'firetext'
            ? $SENDER_ID_FIRETEXT
            : $SENDER_ID_SMSWORKS;
    }
}

/* ───────────────────────────────────────────────
 * Timezone
 * ─────────────────────────────────────────────── */
$TIMEZONE = 'Europe/London';

/* ───────────────────────────────────────────────
 * REDCap project metadata
 * ─────────────────────────────────────────────── */
$BASELINE_EVENT         = 'baseline_arm_1';
$FOLLOWUP_EVENT         = 'followup__1_30_day_arm_1';
$FOLLOWUP_REPEAT_INSTR  = 'goal_setting_assessments';

$FIELD_PHONE         = 'mob_number';
$FIELD_BASELINE_DATE = 'date_baseline';
$FIELD_DAY_NUMBER    = 'fup_day_number';
$FIELD_OPT_OUT       = 'sms_opt_out';

$NEXT_SMS_TRIGGER_FIELD = 'next_sms_trigger_ts';

$SEQUENCE = [
    ['q'=>'q1a','a'=>'q1a_answer'],
    ['q'=>'q1b','a'=>'q1b_answer'],
    ['q'=>'q2a','a'=>'q2a_answer'],
    ['q'=>'q2b','a'=>'q2b_answer'],
    ['q'=>'q3a','a'=>'q3a_answer'],
    ['q'=>'q3b','a'=>'q3b_answer'],
    ['q'=>'q4a','a'=>'q4a_answer'],
    ['q'=>'q4b','a'=>'q4b_answer'],
    ['q'=>'q5a','a'=>'q5a_answer'],
    ['q'=>'q5b','a'=>'q5b_answer'],
];

$SMSW_FIELD_MAP = [
    'q1a'=>['prov'=>'sms_prov_msgid_q1a','status'=>'sms_sent_status_q1a'],
    'q1b'=>['prov'=>'sms_prov_msgid_q1b','status'=>'sms_sent_status_q1b'],
    'q2a'=>['prov'=>'sms_prov_msgid_q2a','status'=>'sms_sent_status_q2a'],
    'q2b'=>['prov'=>'sms_prov_msgid_q2b','status'=>'sms_sent_status_q2b'],
    'q3a'=>['prov'=>'sms_prov_msgid_q3a','status'=>'sms_sent_status_q3a'],
    'q3b'=>['prov'=>'sms_prov_msgid_q3b','status'=>'sms_sent_status_q3b'],
    'q4a'=>['prov'=>'sms_prov_msgid_q4a','status'=>'sms_sent_status_q4a'],
    'q4b'=>['prov'=>'sms_prov_msgid_q4b','status'=>'sms_sent_status_q4b'],
    'q5a'=>['prov'=>'sms_prov_msgid_q5a','status'=>'sms_sent_status_q5a'],
    'q5b'=>['prov'=>'sms_prov_msgid_q5b','status'=>'sms_sent_status_q5b'],
];

/* Limits */
$MAX_DAYS           = 30;
$DEFAULT_PRUNE_KEEP = 5;

/* Scheduling / HELP */
define('Q1A_GUARD_START_HOUR', 7);
define('AUTO_HEAL_WINDOW_START_HOUR', 7);
define('AUTO_HEAL_WINDOW_END_HOUR', 12);

define('REMINDER_ENABLED', true);
define('REMINDER_SECONDS', 3*3600);
define('REMINDER_SENT_MAX', 1);
define('REMINDER_WINDOW_START_HOUR', 8);
define('REMINDER_WINDOW_END_HOUR', 21);

define('HELP_AUTOREPLY_ENABLED', true);
define('HELP_AUTOREPLY_TEXT', "Reply 1–10 for your score today.\nReply 0 to stop messages.\nIf unsure, reply HELP.");
define('HELP_RATE_LIMIT_MINUTES', 60);
define('HELP_FOR_INVALID_ENABLED', true);