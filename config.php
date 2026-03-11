<?php
/**
 * config.php — centralised configuration for REDCap SMS pipeline
 * Prefer environment variables in production; fall back to safe placeholders.
 */

/* ────────────────────────────────────────────────────────────────────────────
 * Logs
 * ──────────────────────────────────────────────────────────────────────────── */
define('LOG_DIR', __DIR__ . '/../logs'); // or set an absolute path; directory will be created by scripts

/* ────────────────────────────────────────────────────────────────────────────
 * REDCap credentials / endpoints
 * ──────────────────────────────────────────────────────────────────────────── */
$REDCAP_API_URL   = getenv('REDCAP_API_URL')   ?: 'https://dm2.pctu.qmul.ac.uk/api/';
$REDCAP_API_TOKEN = getenv('REDCAP_API_TOKEN') ?: 'A0DA196AAB30E19F7935DDBDDAA9BFDB';

/* ────────────────────────────────────────────────────────────────────────────
 * Provider selection & credentials
 * ──────────────────────────────────────────────────────────────────────────── */

// NEW: Choose which provider to use for sending (can be switched at runtime)
$PROVIDER = getenv('SMS_PROVIDER') ?: 'smsworks';   // 'smsworks' | 'firetext'

// FireText
$FIRETEXT_API_KEY = getenv('FIRETEXT_API_KEY') ?: 'z7cTGLKfTHThQuAHbxqX333tfWZI7Q';

/* ────────────────────────────────────────────────────────────────────────────
 * SMS Works authentication (JWT) — restored behavior
 * Priority:
 *   1) Use a supplied JWT (env/config) if present
 *   2) Otherwise, generate from API KEY + SECRET (optional)
 * This block only runs when $PROVIDER === 'smsworks'
 * ──────────────────────────────────────────────────────────────────────────── */

$SMSW_API_KEY    = getenv('SMSW_API_KEY')    ?: 'ce1f714e-ae42-4f54-92f2-230d1662ec93';
$SMSW_API_SECRET = getenv('SMSW_API_SECRET') ?: 'e362eb4b0c8e8519938b3e76c50a5f0bb366d9c35d3e71e2d7d10c29b09c7dcc';


/* 1) Allow a pre‑issued JWT (restored behavior). 
   - Put the EXACT token string here, e.g. "JWT eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9...."
   - Best practice: set it via environment variable SMSW_JWT
*/
$SMSW_JWT_RAW = getenv('SMSW_JWT') ?: ($SMSW_JWT_RAW ?? '');   // <-- if this is non-empty, we will use it as-is

/**
 * 2) Generator (kept for convenience). Only called if no JWT was supplied above.
 *    Returns the token string or throws RuntimeException with a detailed message.
 */
function smsworks_generate_jwt($key, $secret, $opts = []){
    $endpoint = "https://api.thesmsworks.co.uk/v1/auth/token";
    $payload  = json_encode(["key" => $key, "secret" => $secret]);

    $ch = curl_init();
    $headers = ["Content-Type: application/json","Content-Length: " . strlen($payload)];

    // Optional CA bundle / proxy / timeout knobs
    $ca = $opts['ca']      ?? null;   // absolute path to cacert.pem (Windows often needs this)
    $px = $opts['proxy']   ?? null;   // e.g., http://proxy:8080
    $to = $opts['timeout'] ?? 25;     // seconds

    curl_setopt_array($ch, [
        CURLOPT_URL            => $endpoint,
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_TIMEOUT        => $to,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    if ($ca && is_file($ca)) curl_setopt($ch, CURLOPT_CAINFO, $ca);
    if ($px) curl_setopt($ch, CURLOPT_PROXY, $px);

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
        throw new RuntimeException("SMS Works auth/token returned no token | resp: $response");
    }
    return (string)$data['token'];
}

/* 3) Only prepare a JWT when provider is smsworks */
if (($PROVIDER ?? '') === 'smsworks') {
    // If a ready JWT was supplied, use it and skip generation
    if (!empty($SMSW_JWT_RAW)) {
        // Normalize any accidental leading/trailing spaces/newlines
        $SMSW_JWT_RAW = trim($SMSW_JWT_RAW);
        // Optionally validate it looks like a JWT (starts with "JWT ")
        if (stripos($SMSW_JWT_RAW, 'JWT ') !== 0) {
            // Allow both formats: "JWT x.y.z" or raw "x.y.z"; normalize to "JWT ...":
            $SMSW_JWT_RAW = 'JWT ' . $SMSW_JWT_RAW;
        }
    } else {
        // No pre‑issued JWT: attempt generation (optional; comment out if you prefer hard‑fail)
        if (!$SMSW_API_KEY || !$SMSW_API_SECRET) {
            // Hard fail if you require JWT and have no key/secret
            die("SMS Works JWT generation error: missing SMSW_API_KEY and/or SMSW_API_SECRET");
        }
        try {
            // On Windows: consider a CA bundle file to avoid HTTP 0 TLS issues
            // $caBundle = __DIR__ . '/cacert.pem'; // place a bundle here if needed
            $token = smsworks_generate_jwt($SMSW_API_KEY, $SMSW_API_SECRET, [
                // 'ca'      => $caBundle,
                // 'proxy'   => getenv('HTTPS_PROXY') ?: getenv('HTTP_PROXY') ?: null,
                'timeout' => 25,
            ]);
            $SMSW_JWT_RAW = 'JWT ' . trim($token);
        } catch (Throwable $e){
            // Hard fail when smsworks is selected (so you see the real cause during testing)
            die("SMS Works JWT generation error: " . $e->getMessage());
        }
    }
}

/* ───────── Generate SMSW_JWT_RAW only when provider requires it ───────── */
$SMSW_JWT_RAW = null;
if (($PROVIDER ?? '') === 'smsworks') {
    try {
        // On Windows, strongly consider pointing to a CA bundle:
        // $caBundle = __DIR__ . '/cacert.pem'; // download from curl.se/ca if needed
        $SMSW_JWT_RAW = smsworks_generate_jwt($SMSW_API_KEY, $SMSW_API_SECRET, [
            // 'ca'      => $caBundle,
            // 'proxy'   => getenv('HTTPS_PROXY') ?: getenv('HTTP_PROXY') ?: null,
            'timeout' => 25,
        ]);
    } catch (Throwable $e){
        // Fail softly so FireText (or other flows) aren’t blocked by config load
        error_log("SMS Works JWT generation error: ".$e->getMessage());
        // Optionally: die if you want hard-fail when provider is smsworks
        die("SMS Works JWT generation error: ".$e->getMessage());
    }
}

/* ────────────────────────────────────────────────────────────────────────────
 * Sender IDs & Reply Numbers (VMNs)
 * ──────────────────────────────────────────────────────────────────────────── */

/**
 * Legacy fallback Sender ID (kept for backwards compatibility).
 * This is also used if provider-specific sender IDs are not set.
 */
$SENDER_ID = getenv('SMS_SENDER_ID') ?: '447860064498';   // your real SMS Works VMN

/**
 * Per‑provider Sender IDs
 * IMPORTANT:
 *   - For 2‑way messaging, Sender ID MUST be a numeric VMN.
 *   - For 1‑way messaging, it may be alphanumeric (3–11 chars, A‑Z/0‑9).
 *
 * Currently you only supplied the SMS Works sender ("447860064498"), 
 * so FireText defaults to the same until you provide a FireText VMN.
 */
$SENDER_ID_SMSWORKS = getenv('SENDER_ID_SMSWORKS') ?: '447860064498';
$SENDER_ID_FIRETEXT = getenv('SENDER_ID_FIRETEXT') ?: '447860064498';

/**
 * Per‑provider Reply Numbers (VMNs)
 * These are mandatory for inbound MO routing.
 * Both default to your existing VMN until you add a FireText VMN.
 */
$SMSW_REPLY_NUMBER     = getenv('SMSW_REPLY_NUMBER')     ?: '447860064498';
$FIRETEXT_REPLY_NUMBER = getenv('FIRETEXT_REPLY_NUMBER') ?: '447860064498';

/**
 * Helper: Select correct sender ID based on the active provider.
 */
if (!function_exists('current_sender_id')) {
    function current_sender_id(){
        global $PROVIDER, $SENDER_ID_SMSWORKS, $SENDER_ID_FIRETEXT, $SENDER_ID;

        if ($PROVIDER === 'firetext') return $SENDER_ID_FIRETEXT;
        if ($PROVIDER === 'smsworks') return $SENDER_ID_SMSWORKS;

        return $SENDER_ID;  // fallback
    }
}

/* ────────────────────────────────────────────────────────────────────────────
 * Timezone
 * ──────────────────────────────────────────────────────────────────────────── */
$TIMEZONE = getenv('APP_TIMEZONE') ?: 'Europe/London';

/* ────────────────────────────────────────────────────────────────────────────
 * REDCap events / instrument (confirmed names)
 * ──────────────────────────────────────────────────────────────────────────── */
$BASELINE_EVENT         = 'baseline_arm_1';
$FOLLOWUP_EVENT         = 'followup__1_30_day_arm_1';
$FOLLOWUP_REPEAT_INSTR  = 'goal_setting_assessments';

/* ────────────────────────────────────────────────────────────────────────────
 * Field names (confirmed)
 * ──────────────────────────────────────────────────────────────────────────── */
$FIELD_PHONE         = 'mob_number';
$FIELD_BASELINE_DATE = 'date_baseline';     // stored as D-M-Y in UI; scripts convert to Y-m-d for API
$FIELD_DAY_NUMBER    = 'fup_day_number';
$FIELD_OPT_OUT       = 'sms_opt_out';

// Touch-on-change trigger (optional) – a simple text/datetime field in the follow-up instrument
$NEXT_SMS_TRIGGER_FIELD = 'next_sms_trigger_ts';

// Optional explicit complete field (otherwise <instrument>_complete is used)
// $FORM_COMPLETE_FIELD = 'goal_setting_assessments_complete';

/* ────────────────────────────────────────────────────────────────────────────
 * Day sequence (q-code -> answer field). For provider-id/status, see $SMSW_FIELD_MAP.
 * ──────────────────────────────────────────────────────────────────────────── */
$SEQUENCE = [
    ['q' => 'q1a', 'a' => 'q1a_answer'],
    ['q' => 'q1b', 'a' => 'q1b_answer'],
    ['q' => 'q2a', 'a' => 'q2a_answer'],
    ['q' => 'q2b', 'a' => 'q2b_answer'],
    ['q' => 'q3a', 'a' => 'q3a_answer'],
    ['q' => 'q3b', 'a' => 'q3b_answer'],
    ['q' => 'q4a', 'a' => 'q4a_answer'],
    ['q' => 'q4b', 'a' => 'q4b_answer'],
    ['q' => 'q5a', 'a' => 'q5a_answer'],
    ['q' => 'q5b', 'a' => 'q5b_answer'],
];

/* ────────────────────────────────────────────────────────────────────────────
 * SMS Works / provider-id + status fields mapping (used by outbound)
 * If you also want to store FireText provider IDs per question, reuse the same keys.
 * ──────────────────────────────────────────────────────────────────────────── */
$SMSW_FIELD_MAP = [
    'q1a' => ['prov' => 'sms_prov_msgid_q1a', 'status' => 'sms_sent_status_q1a'],
    'q1b' => ['prov' => 'sms_prov_msgid_q1b', 'status' => 'sms_sent_status_q1b'],
    'q2a' => ['prov' => 'sms_prov_msgid_q2a', 'status' => 'sms_sent_status_q2a'],
    'q2b' => ['prov' => 'sms_prov_msgid_q2b', 'status' => 'sms_sent_status_q2b'],
    'q3a' => ['prov' => 'sms_prov_msgid_q3a', 'status' => 'sms_sent_status_q3a'],
    'q3b' => ['prov' => 'sms_prov_msgid_q3b', 'status' => 'sms_sent_status_q3b'],
    'q4a' => ['prov' => 'sms_prov_msgid_q4a', 'status' => 'sms_sent_status_q4a'],
    'q4b' => ['prov' => 'sms_prov_msgid_q4b', 'status' => 'sms_sent_status_q4b'],
    'q5a' => ['prov' => 'sms_prov_msgid_q5a', 'status' => 'sms_sent_status_q5a'],
    'q5b' => ['prov' => 'sms_prov_msgid_q5b', 'status' => 'sms_sent_status_q5b'],
];

/* ────────────────────────────────────────────────────────────────────────────
 * Limits / defaults
 * ──────────────────────────────────────────────────────────────────────────── */
$MAX_DAYS           = 30;                                   // never create beyond Day 30
$DEFAULT_PRUNE_KEEP = (int)(getenv('PRUNE_KEEP') ?: 5);     // keep N instances when pruning

/* ────────────────────────────────────────────────────────────────────────────
 * Scheduling, reminders, and HELP auto-reply (CONFIGURABLE)
 * Values can be overridden by environment variables at deploy time.
 * ──────────────────────────────────────────────────────────────────────────── */

// q1a will not send before this hour (24h, server timezone)
if (!defined('Q1A_GUARD_START_HOUR')) define('Q1A_GUARD_START_HOUR', 7);

// AUTO-HEAL window for missed q1a (inclusive start/end hours)
if (!defined('AUTO_HEAL_WINDOW_START_HOUR')) define('AUTO_HEAL_WINDOW_START_HOUR', 7);
if (!defined('AUTO_HEAL_WINDOW_END_HOUR'))   define('AUTO_HEAL_WINDOW_END_HOUR', 12);

// Reminders: enable, age threshold, max reminders per question
if (!defined('REMINDER_ENABLED'))      define('REMINDER_ENABLED', true);
if (!defined('REMINDER_SECONDS'))      define('REMINDER_SECONDS', 3 * 3600); // 3 hours
if (!defined('REMINDER_SENT_MAX'))     define('REMINDER_SENT_MAX', 1);       // one reminder

// NEW: Reminders daytime window (inclusive hours). Set both to null to disable gating.
if (!defined('REMINDER_WINDOW_START_HOUR')) define('REMINDER_WINDOW_START_HOUR', 8);
if (!defined('REMINDER_WINDOW_END_HOUR'))   define('REMINDER_WINDOW_END_HOUR', 21);

// HELP auto-reply master switch
if (!defined('HELP_AUTOREPLY_ENABLED')) define('HELP_AUTOREPLY_ENABLED', true);

// HELP message sent to participants (also used for invalid inputs, if enabled)
if (!defined('HELP_AUTOREPLY_TEXT')) define('HELP_AUTOREPLY_TEXT',
    "Reply 1–10 for your score today.\nReply 0 to stop messages.\nIf unsure, reply HELP."
);

// Rate-limit HELP auto-replies per record (minutes)
if (!defined('HELP_RATE_LIMIT_MINUTES')) define('HELP_RATE_LIMIT_MINUTES', 60);

// Also send HELP auto-reply for invalid inputs (non-numeric/unsupported)
if (!defined('HELP_FOR_INVALID_ENABLED')) define('HELP_FOR_INVALID_ENABLED', true);