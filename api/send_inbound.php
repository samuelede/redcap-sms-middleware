<?php
/**
 * send_inbound.php — STABLE & SIMPLE VERSION
 *
 * Responsibilities:
 *  - Match phone → record (baseline event)
 *  - Compute day from baseline
 *  - Find earliest unanswered question for that day
 *  - Save replies
 *
 * Inbound does NOT:
 *  - care whether a question was sent
 *  - advance questions
 *  - trigger outbound
 *
 * Outbound cron owns sequencing.
 */

require_once __DIR__ . '/config.php';
date_default_timezone_set($TIMEZONE);

/* ------------------------------------------------------------
 * Logging
 * ------------------------------------------------------------ */
if (!defined('LOG_DIR')) {
    $base = dirname(__DIR__);
    $logDir = $base . DIRECTORY_SEPARATOR . 'logs';
    if (!is_dir($logDir)) @mkdir($logDir, 0775, true);
    if (!is_writable($logDir)) $logDir = sys_get_temp_dir();
    define('LOG_DIR', $logDir);
}

$INBOUND_LOG = LOG_DIR . DIRECTORY_SEPARATOR . 'inbound.log';

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
 * Helpers
 * ------------------------------------------------------------ */
define('SPECIAL_SKIP_CODE', '666');

function normalise_msisdn($raw){
    $d = preg_replace('/\D+/', '', (string)$raw);

    // 07xxxxxxxxx → 447xxxxxxxxx
    if (strpos($d, '07') === 0) {
        return '44' . substr($d, 1);
    }

    // 7xxxxxxxxx → 447xxxxxxxxx (defensive)
    if (strlen($d) === 10 && $d[0] === '7') {
        return '44' . $d;
    }

    // Already E.164
    if (strpos($d, '44') === 0) {
        return $d;
    }

    return $d;
}

function sanitize_int_1_10($s) {
    if (!preg_match('/^\d+$/', trim($s))) return null;
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
            return (int)$dt->diff($today)->format('%a');
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
    foreach ($fields as $i => $f) $p["fields[$i]"] = $f;
    foreach ($events as $i => $e) $p["events[$i]"] = $e;
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
        : (defined('SENDER_ID') ? SENDER_ID : null);

    if (!$sender || !$SMSW_JWT_RAW) return;

    $endpoint = "https://api.thesmsworks.co.uk/v1/message/send";
    $toNorm = normalise_msisdn($to);

    $auth = stripos($SMSW_JWT_RAW, 'JWT ') === 0
        ? "Authorization: {$SMSW_JWT_RAW}"
        : "Authorization: JWT {$SMSW_JWT_RAW}";

    $body = [
        'destination' => $toNorm,
        'sender'      => $sender,
        'content'     => $text
    ];

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $endpoint,
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [$auth, 'Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode($body),
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    curl_exec($ch);
    curl_close($ch);
}

/* ------------------------------------------------------------
 * MAIN
 * ------------------------------------------------------------ */
try {
    $raw = file_get_contents('php://input');
    $payload = json_decode($raw, true) ?: $_POST ?: $_GET;

    $from = $payload['source'] ?? $payload['from'] ?? '';
    $text = trim((string)($payload['content'] ?? ''));

    inlog("RECEIVED from={$from} content='{$text}'");

    if ($text === '') {
        http_response_code(200); echo "OK"; exit;
    }

    $score = sanitize_int_1_10($text);

    /* ---------- Phone → record (baseline event) ---------- */
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
        inlog("ABORT: phone not matched to any record");
        http_response_code(200); echo "OK"; exit;
    }

    /* ---------- Determine active follow-up instance ---------- */
    /*
    * Rule:
    * - If a previous follow-up instance is incomplete, always write replies there
    * - Only advance to a new instance once the previous one is marked complete
    */

    // Export follow-up instances with completion status
    $followupRows = redcap_export_records(
        $REDCAP_API_TOKEN,
        $REDCAP_API_URL,
        [
            'record_id',
            $FOLLOWUP_REPEAT_INSTR . '_complete'
        ],
        [$FOLLOWUP_EVENT]
    );

    $day = null;

    // Find first incomplete instance
    foreach ($followupRows as $r) {
        if ((int)$r['record_id'] !== $rid) continue;

        $inst = (int)($r['redcap_repeat_instance'] ?? 0);
        if ($inst < 1) continue;

        $complete = trim((string)($r[$FOLLOWUP_REPEAT_INSTR . '_complete'] ?? '0'));

        if ($complete !== '2') {
            $day = $inst;
            break;
        }
    }

    // If all previous instances are complete, fall back to calendar day
    if ($day === null) {
        $day = get_today_day_number_from_baseline($baselineDate);
    }

    // Safety check
    if ($day === null) {
        inlog("ABORT: cannot determine active follow-up instance");
        http_response_code(200);
        echo "OK";
        exit;
    }

    /* ---------- Find earliest unanswered question ---------- */
    // ------------------------------------------------------------
    // Export answers + provider-id fields so inbound can verify
    // that a question was actually sent
    // ------------------------------------------------------------
    $fields = ['record_id'];

    foreach ($SEQUENCE as $s) {
        // answer field
        $fields[] = $s['a'];

        // provider-id field (if defined)
        $q = $s['q'];
        if (!empty($SMSW_FIELD_MAP[$q]['prov'])) {
            $fields[] = $SMSW_FIELD_MAP[$q]['prov'];
        }
    }

    $rows = redcap_export_records(
        $REDCAP_API_TOKEN,
        $REDCAP_API_URL,
        $fields,
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

    $base = [
        'record_id' => $rid,
        'redcap_event_name' => $FOLLOWUP_EVENT,
        'redcap_repeat_instrument' => $FOLLOWUP_REPEAT_INSTR,
        'redcap_repeat_instance' => $day
    ];

    /* ---------- OPT-OUT ---------- */
    if ($text === '0') {
        $row = $base;
        $row[$FIELD_OPT_OUT] = '0';
        redcap_import_records($REDCAP_API_TOKEN, $REDCAP_API_URL, [$row]);
        inlog("OPT-OUT record={$rid} day={$day}");
        http_response_code(200); echo "OK"; exit;
    }

    /* ---------- HELP ---------- */
    if (strtoupper($text) === 'HELP') {
        $row = $base;
        $row['help_requested'] = '1';
        redcap_import_records($REDCAP_API_TOKEN, $REDCAP_API_URL, [$row]);

        send_help_sms_smsworks(
            $from,
            defined('HELP_AUTOREPLY_TEXT')
                ? HELP_AUTOREPLY_TEXT
                : "Reply 1–10 for your score today. Reply 0 to stop messages."
        );

        inlog("HELP record={$rid} day={$day}");
        http_response_code(200); echo "OK"; exit;
    }

    /* ---------- ANSWERS (1–10 or 666; only if question was sent) ---------- */
    if ($text === SPECIAL_SKIP_CODE || $score !== null) {

        if (!$answerField) {
            inlog("ANSWER ignored: no unanswered question for record {$rid} day {$day}");
            http_response_code(200);
            echo "OK";
            exit;
        }

        // Determine question code for this answer field
        $qCode = null;
        foreach ($SEQUENCE as $s) {
            if ($s['a'] === $answerField) {
                $qCode = $s['q'];
                break;
            }
        }

        // Provider-id field proves the question was sent
        $provField = $qCode ? ($SMSW_FIELD_MAP[$qCode]['prov'] ?? null) : null;
        $provVal   = $provField ? trim((string)($row[$provField] ?? '')) : '';

        // [X] Do NOT accept replies for questions that were never sent
        if ($provVal === '') {
            inlog("ANSWER ignored: {$qCode} not sent yet (record {$rid} day {$day})");
            http_response_code(200);
            echo "OK";
            exit;
        }

        // ✅ Safe to record answer
        $row = $base;
        $row[$answerField] = ($text === SPECIAL_SKIP_CODE ? '666' : (string)$score);

        redcap_import_records(
            $REDCAP_API_TOKEN,
            $REDCAP_API_URL,
            [$row]
        );

        // ------------------------------------------------------------
        // Trigger outbound via HTTP trigger (minimal & safe)
        // ------------------------------------------------------------
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $BASE_URL . '/api/trigger_outbound.php',
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 2,
            CURLOPT_POSTFIELDS     => http_build_query([
                'secret' => OUTBOUND_TRIGGER_SECRET
            ]),
        ]);
        curl_exec($ch);
        curl_close($ch);

        inlog("ANSWER recorded record={$rid} day={$day} {$answerField}={$row[$answerField]}");

        /* ============================================================
        * MARK FOLLOW-UP INSTRUMENT COMPLETE
        * (666 counts as answered)
        * ============================================================ */
        $requiredAnswers = [
            'q1a_answer','q1b_answer',
            'q2a_answer','q2b_answer',
            'q3a_answer','q3b_answer',
            'q4a_answer','q4b_answer',
            'q5a_answer','q5b_answer'
        ];

        $checkRows = redcap_export_records(
            $REDCAP_API_TOKEN,
            $REDCAP_API_URL,
            array_merge(
                ['record_id', $FOLLOWUP_REPEAT_INSTR . '_complete'],
                $requiredAnswers
            ),
            [$FOLLOWUP_EVENT]
        );

        foreach ($checkRows as $r) {
            if ((int)$r['record_id'] !== $rid) continue;
            if ((int)($r['redcap_repeat_instance'] ?? 0) !== $day) continue;

            foreach ($requiredAnswers as $f) {
                if (trim((string)($r[$f] ?? '')) === '') {
                    http_response_code(200);
                    echo "OK";
                    exit;
                }
            }

            $completeRow = [
                'record_id'                => $rid,
                'redcap_event_name'        => $FOLLOWUP_EVENT,
                'redcap_repeat_instrument' => $FOLLOWUP_REPEAT_INSTR,
                'redcap_repeat_instance'   => $day,
                $FOLLOWUP_REPEAT_INSTR . '_complete' => '2'
            ];

            redcap_import_records(
                $REDCAP_API_TOKEN,
                $REDCAP_API_URL,
                [$completeRow]
            );

            inlog("FORM COMPLETE record={$rid} day={$day}");
            break;
        }

        http_response_code(200);
        echo "OK";
        exit;
    }

    /* ---------- INVALID ---------- */
    send_help_sms_smsworks(
        $from,
        defined('HELP_AUTOREPLY_TEXT')
            ? HELP_AUTOREPLY_TEXT
            : "Reply 1–10 for your score today. Reply 0 to stop messages. Reply HELP for help."
    );

    inlog("INVALID record={$rid} day={$day} text='{$text}'");
    http_response_code(200); echo "OK"; exit;

} catch (Throwable $e) {
    inlog("UNCAUGHT ".$e->getMessage());
    http_response_code(200);
    echo "OK";
}