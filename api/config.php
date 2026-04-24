<?php
/**
 * config.php — shared configuration
 */

require_once __DIR__ . '/../secure/secrets.php';

/* ------------------------------------------------------------
 * Environment
 * ------------------------------------------------------------ */
$TIMEZONE = 'Europe/London';
date_default_timezone_set($TIMEZONE);

/* ------------------------------------------------------------
 * REDCap
 * ------------------------------------------------------------ */
$REDCAP_API_URL   = REDCAP_API_URL;
$REDCAP_API_TOKEN = REDCAP_API_TOKEN_SECRET;

// ------------------------------------------------------------
// Outbound trigger configuration
// ------------------------------------------------------------

// Shared secret used by trigger_outbound.php
define('OUTBOUND_TRIGGER_SECRET', 'a0fa68fd744017947c645351287ad97619ef6d1a425a5e0efac8f05735bca553');

// Base URL where trigger_outbound.php is reachable
// Examples:
//   Local dev: http://localhost:8080
//   Ngrok:     https://xxxx.ngrok-free.dev
//   Prod:      https://sms.yourdomain.co.uk
define('BASE_URL', 'http://localhost:8080');

/* ------------------------------------------------------------
 * Provider
 * ------------------------------------------------------------ */
$PROVIDER = 'smsworks';

/* ------------------------------------------------------------
 * Defaults
 * ------------------------------------------------------------ */
$DEFAULT_PRUNE_KEEP = 5;
$MAX_DAYS = 30;

/* ------------------------------------------------------------
 * Events / instruments
 * ------------------------------------------------------------ */
$BASELINE_EVENT        = 'baseline_arm_1';
$FOLLOWUP_EVENT        = 'followup_1__30_day_arm_1';
$FOLLOWUP_REPEAT_INSTR = 'goal_setting_assessments';

/* ------------------------------------------------------------
 * Fields
 * ------------------------------------------------------------ */
$FIELD_PHONE           = 'mob_number';
$FIELD_BASELINE_DATE   = 'date_baseline';
$FIELD_ASSESSMENT_DATE = 'date_assessment';
$FIELD_DAY_NUMBER      = 'fup_day_number';
$FIELD_OPT_OUT         = 'sms_opt_out';

// ------------------------------------------------------------
// Follow-up Day question templates
// ------------------------------------------------------------

// Template for q1a (first question of each follow-up day)
// Available placeholders:
//   {record_id} → REDCap record_id
//   {day}       → follow-up day / instance number
define(
    'Q1A_TEXT_TEMPLATE',
    "CoSMART RID:{record_id} - Day {day}:q1a\n" .
    "How would you rate from 1-10 how you/your child was able to perform ride bicycle today?"
);

/* ------------------------------------------------------------
 * Question sequence
 * ------------------------------------------------------------ */
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

/* ------------------------------------------------------------
 * SMS Works provider field mapping
 * ------------------------------------------------------------ */
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

/* ------------------------------------------------------------
 * Guard windows
 * ------------------------------------------------------------ */
define('Q1A_GUARD_START_HOUR', 1);

/* ------------------------------------------------------------
 * SMS Works JWT generator (RAW TOKEN ONLY)
 * ------------------------------------------------------------ */
function smsworks_generate_jwt(string $key, string $secret): string {

    $endpoint = 'https://api.thesmsworks.co.uk/v1/auth/token';

    $payload = json_encode([
        'key'    => $key,
        'secret' => $secret
    ]);

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $endpoint,
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_TIMEOUT        => 20,
    ]);

    $out  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($code !== 200) {
        throw new RuntimeException("SMSW auth failed ($code): $out $err");
    }

    $json = json_decode($out, true);
    if (empty($json['token'])) {
        throw new RuntimeException("SMSW auth response missing token");
    }

    return $json['token']; // ✅ RAW
}

/* ------------------------------------------------------------
 * Generate JWT ONCE
 * ------------------------------------------------------------ */
$SMSW_JWT_RAW = smsworks_generate_jwt(
    SMSW_API_KEY,
    SMSW_API_SECRET
);

error_log("CONFIG JWT OK, len=" . strlen($SMSW_JWT_RAW));

/* ------------------------------------------------------------
 * Sender
 * ------------------------------------------------------------ */
function current_sender_id() {
    return SMSW_REPLY_NUMBER;
}