<?php
/**
 * send_inbound.php — FINAL DETERMINISTIC VERSION
 * Updated: 20 Apr 2026
 *
 * SMS Works inbound handler.
 *
 * Rules:
 *  - Replies are mapped to the EARLIEST unanswered question for the current day
 *  - No reliance on outbound text (SMS Works does not echo it)
 *  - Late replies are logged but never mis-mapped
 *
 * Valid replies:
 *   1..10  → save answer
 *   666    → save 666, advance
 *   0      → opt-out immediately
 *   HELP   → help auto-reply
 *   other  → help + delayed resend
 */

require_once __DIR__ . '/config.php';
date_default_timezone_set($TIMEZONE);

/* ------------------------------------------------------------
 * Logging (MUST be defined before first use)
 * ------------------------------------------------------------ */
if (!defined('LOG_DIR')) {
    $base = dirname(__DIR__); // project root
    $logDir = $base . DIRECTORY_SEPARATOR . 'logs';

    if (!is_dir($logDir)) {
        @mkdir($logDir, 0775, true);
    }

    if (!is_dir($logDir) || !is_writable($logDir)) {
        $logDir = sys_get_temp_dir(); // safe fallback
    }

    define('LOG_DIR', $logDir);
}

$INBOUND_LOG = rtrim(LOG_DIR, DIRECTORY_SEPARATOR)
             . DIRECTORY_SEPARATOR
             . 'inbound.log';

function inlog($msg) {
    global $INBOUND_LOG;
    @file_put_contents(
        $INBOUND_LOG,
        '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL,
        FILE_APPEND | LOCK_EX
    );
}
inlog('=== INBOUND START ===');

/* ------------------------------------------------------------
 * FIRST safe log call
 * ------------------------------------------------------------ */
inlog(
    "DEBUG CONFIG: SMSW_JWT_RAW=".(isset($SMSW_JWT_RAW)?'SET':'MISSING')
    .", SENDER_ID=".(isset($SENDER_ID)?'SET':'MISSING')
);

/* ------------------------------------------------------------
 * Configuration
 * ------------------------------------------------------------ */
define('SPECIAL_SKIP_CODE', '666');
define('INVALID_RESEND_DELAY_SECONDS', 7);

/* ------------------------------------------------------------
 * Helpers
 * ------------------------------------------------------------ */
function normalise_msisdn($raw) {
    $d = preg_replace('/\D+/', '', (string)$raw);
    if (strpos($d, '07') === 0) return '44' . substr($d, 1);
    return $d;
}

function sanitize_int_1_10($s) {
    if (!preg_match('/^-?\d+$/', trim($s))) return null;
    $v = (int)$s;
    return ($v >= 1 && $v <= 10) ? $v : null;
}

function get_today_day_number_from_baseline($baselineRaw){
    if (!$baselineRaw) return null;

    $raw = trim((string)$baselineRaw);
    $raw = preg_replace('/[\/\.]/', '-', $raw);

    foreach (['!Y-m-d', '!d-m-Y', '!j-n-Y'] as $fmt) {
        $dt = DateTime::createFromFormat($fmt, $raw);
        if ($dt && $dt->format('Y') >= 1900) {
            $today = new DateTime('today');
            return (int)$dt->diff($today)->format('%a'); // Day 0 baseline
        }
    }

    return null;
}

/* ------------------------------------------------------------
 * REDCap API helpers
 * ------------------------------------------------------------ */
function redcap_api_post($url, $data) {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_POSTFIELDS => $data,
        CURLOPT_TIMEOUT => 30,
    ]);
    $out = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code < 200 || $code >= 300) {
        throw new RuntimeException("REDCap HTTP $code: $out");
    }
    return $out;
}

function redcap_export_records($token, $url, array $fields, array $events) {
    $p = [
        'token' => $token,
        'content' => 'record',
        'format' => 'json',
        'type' => 'flat'
    ];
    foreach ($fields as $i => $f) {
        $p["fields[$i]"] = $f;
    }
    foreach ($events as $i => $e) {
        $p["events[$i]"] = $e;
    }
    return json_decode(redcap_api_post($url, $p), true);
}

function redcap_import_records($token, $url, array $rows) {
    if (!$rows) return;
    $p = [
        'token' => $token,
        'content' => 'record',
        'format' => 'json',
        'type' => 'flat',
        'overwriteBehavior' => 'normal',
        'data' => json_encode($rows),
        'returnContent' => 'count',
        'returnFormat' => 'json'
    ];
    redcap_api_post($url, $p);
}

function send_help_sms_smsworks($to, $text) {
    global $SMSW_JWT_RAW;

    $sender = function_exists('current_sender_id')
        ? current_sender_id()
        : (defined('SENDER_ID') ? SENDER_ID : ($GLOBALS['SENDER_ID'] ?? null));

    if (empty($SMSW_JWT_RAW) || empty($sender)) {
        inlog("HELP SEND ABORTED: missing SMSW_JWT_RAW or sender");
        return false;
    }

    $endpoint = "https://api.thesmsworks.co.uk/v1/message/send";
    $toNorm = normalise_msisdn($to);

    $authHeader = (stripos($SMSW_JWT_RAW, 'JWT ') === 0)
        ? "Authorization: {$SMSW_JWT_RAW}"
        : "Authorization: JWT {$SMSW_JWT_RAW}";

    $body = [
        'destination' => $toNorm,
        'sender'      => $sender,
        'content'     => $text
    ];

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
        CURLOPT_TIMEOUT        => 15,
    ]);

    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($code < 200 || $code >= 300) {
        inlog("HELP SEND FAILED: HTTP {$code} resp={$resp} err={$err}");
        return false;
    }

    inlog("HELP SENT OK to {$toNorm} from {$sender}");
    return true;
}

/* ------------------------------------------------------------
 * MAIN
 * ------------------------------------------------------------ */
try {
    $raw = file_get_contents('php://input');
    $payload = json_decode($raw, true) ?: $_POST ?: $_GET;

    // SMS Works payload normalisation
    $from = $payload['source'] ?? $payload['from'] ?? '';
    $text = trim((string)($payload['content'] ?? ''));

    inlog("RECEIVED from={$from} content='{$text}'");
    // Parse numeric score (1–10) once
    $score = sanitize_int_1_10($text);

    if ($text === '') {
        inlog("ABORT: empty content (likely browser access, delivery report, or malformed inbound payload)");
        http_response_code(200);
        echo "OK";
        return;
    }

    /* ---------- Find record by phone ---------- */
    $baseline = redcap_export_records(
        $REDCAP_API_TOKEN,
        $REDCAP_API_URL,
        ['record_id', $FIELD_PHONE, $FIELD_BASELINE_DATE],
        [$BASELINE_EVENT]
    );

    $rid = null;
    $baselineDate = null;
    foreach ($baseline as $r) {
        if (normalise_msisdn($r[$FIELD_PHONE] ?? '') === normalise_msisdn($from)) {
            $rid = (int)$r['record_id'];
            $baselineDate = $r[$FIELD_BASELINE_DATE] ?? null;
            break;
        }
    }

    if (!$rid) {
        inlog("ABORT: phone not matched to any record — inbound from={$from}");
        http_response_code(200);
        echo "OK";
        return;
    }

    /* ---------- Determine day ---------- */
    $day = get_today_day_number_from_baseline($baselineDate);
    if ($day === null) {
        inlog(
            "ABORT: cannot compute day — invalid or missing date_baseline for record {$rid}; raw value='{$baselineDate}'"
        );
        http_response_code(200);
        echo "OK";
        return;
    }

    /* ---------- Find earliest unanswered question ---------- */
    $rows = redcap_export_records(
        $REDCAP_API_TOKEN,
        $REDCAP_API_URL,
        array_merge(['record_id'], array_column($SEQUENCE, 'a')),
        [$FOLLOWUP_EVENT]
    );

    $answerField = null;
    foreach ($rows as $row) {
        if ((int)$row['record_id'] !== $rid) continue;
        if ((int)($row['redcap_repeat_instance'] ?? 0) !== $day) continue;
        foreach ($SEQUENCE as $s) {
            if (trim((string)($row[$s['a']] ?? '')) === '') {
                $answerField = $s['a'];
                break 2;
            }
        }
    }
    
    // DEBUG: show which unanswered fields inbound sees
    $unanswered = [];
    foreach ($rows as $row) {
        if ((int)$row['record_id'] !== $rid) continue;
        if ((int)($row['redcap_repeat_instance'] ?? 0) !== $day) continue;
        foreach ($SEQUENCE as $s) {
            if (trim((string)($row[$s['a']] ?? '')) === '') {
                $unanswered[] = $s['a'];
            }
        }
    }
    inlog(
        "DEBUG inbound scan record={$rid} day={$day} — unanswered answer fields=" .
        json_encode(array_values(array_unique($unanswered)))
    );

    if (!$answerField) {
        inlog(
        "ABORT: no unanswered question — check question text and answers for record {$rid} day {$day}");
        http_response_code(200); echo "OK"; exit;
    }

    $base = [
        'record_id' => $rid,
        'redcap_event_name' => $FOLLOWUP_EVENT,
        'redcap_repeat_instrument' => $FOLLOWUP_REPEAT_INSTR,
        'redcap_repeat_instance' => $day
    ];

    /* ---------- OPT-OUT ---------- */
    if ($text === '0') {

        inlog("OPT-OUT received record={$rid} day={$day}");

        if ($day > 0) {
            $optRow = [
                'record_id'                => $rid,
                'redcap_event_name'        => $FOLLOWUP_EVENT,
                'redcap_repeat_instrument' => $FOLLOWUP_REPEAT_INSTR,
                'redcap_repeat_instance'   => $day,
                $FIELD_OPT_OUT             => '0'
            ];

            try {
                redcap_import_records(
                    $REDCAP_API_TOKEN,
                    $REDCAP_API_URL,
                    [$optRow]
                );
                inlog("OPT-OUT recorded in REDCap for record {$rid} day {$day}");
            } catch (Throwable $e) {
                inlog("OPT-OUT REDCap write failed for record {$rid} day {$day}: ".$e->getMessage());
            }
        } else {
            inlog("OPT-OUT on Day 0 — not writing follow-up opt-out");
        }

        // Optional: confirmation SMS
        // send_help_sms_smsworks($from, "You will no longer receive messages. Thank you.");

        http_response_code(200);
        echo "OK";
        exit;
    }
      
    /* ---------- HELP ---------- */
    if (strtoupper($text) === 'HELP') {

        inlog("HELP received record={$rid} day={$day}");

        if ($day > 0) {
            $helpRow = [
                'record_id'                => $rid,
                'redcap_event_name'        => $FOLLOWUP_EVENT,
                'redcap_repeat_instrument' => $FOLLOWUP_REPEAT_INSTR,
                'redcap_repeat_instance'   => $day,
                'help_requested'           => 'HELP'
            ];

            inlog("HELP DEBUG: importing ".json_encode($helpRow));

            $resp = redcap_import_records(
                $REDCAP_API_TOKEN,
                $REDCAP_API_URL,
                [$helpRow]
            );

            inlog("HELP DEBUG: REDCap response ".json_encode($resp));
        } else {
            inlog("HELP on Day 0 — not writing help_requested");
        }

        // Always send HELP SMS
        $helpText = defined('HELP_AUTOREPLY_TEXT')
            ? HELP_AUTOREPLY_TEXT
            : "Reply 1–10 for your score today. Reply 0 to stop messages.";

        send_help_sms_smsworks($from, $helpText);

        http_response_code(200);
        echo "OK";
        exit;
    }

    /* ---------- SPECIAL 666 ---------- */
    if ($text === SPECIAL_SKIP_CODE) {
        $row = $base;
        $row[$answerField] = SPECIAL_SKIP_CODE;
        redcap_import_records(...);

        inlog("SPECIAL-666 record={$rid} day={$day}");
        http_response_code(200);
        echo "OK";
        return;
    }

    /* ---------- VALID 1–10 ---------- */
    if ($score !== null) {
        $row = $base;
        $row[$answerField] = (string)$score;
        redcap_import_records($REDCAP_API_TOKEN, $REDCAP_API_URL, [$row]);

        inlog("ANSWER record={$rid} day={$day} {$answerField}={$score}");
        http_response_code(200);
        echo "OK";
        return;
    }

    /* ---------- INVALID ---------- */
    inlog(
        "INVALID record={$rid} day={$day} text='{$text}' — sending HELP auto-reply"
    );

    $helpText = defined('HELP_AUTOREPLY_TEXT')
        ? HELP_AUTOREPLY_TEXT
        : "Reply 1–10 for your score today. Reply 0 to stop messages. Reply HELP for help.";

    send_help_sms_smsworks($from, $helpText);

    http_response_code(200);
    echo "OK";
    exit;

} catch (Throwable $e) {
    inlog("UNCAUGHT ".$e->getMessage());
    http_response_code(200);
    echo "OK";
}