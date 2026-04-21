<?php
/**
 * cron_hourly.php
 * 
 * Purpose:
 *   Runs hourly via Windows Task Scheduler to guarantee:
 *     - q1a auto-heal (morning safety net)
 *     - reminders (every 3 days/hours)
 *     - retry due items
 *     - ensures SMS pipeline runs even if DET or inbound auto-trigger fails
 * 
 * This script:
 *   - Logs its invocation
 *   - Calls send_outbound.php
 *   - Skips sending q1a if the record has already responded
 */

$base = __DIR__;
$logFile = $base . './logs/cron_hourly.log';

// Make sure logs folder exists
if (!is_dir(dirname($logFile))) {
    @mkdir(dirname($logFile), 0775, true);
}

// Log start
file_put_contents($logFile,
    "[".date('Y-m-d H:i:s')."] Hourly cron invoked.\n",
    FILE_APPEND | LOCK_EX
);

// Load outbound sender
$outbound = $base . '/send_outbound.php';
if (!file_exists($outbound)) {
    file_put_contents($logFile,
        "[".date('Y-m-d H:i:s')."] ERROR: send_outbound.php not found.\n",
        FILE_APPEND | LOCK_EX
    );
    exit;
}

// Run outbound safely
try {
    require $outbound;
    file_put_contents($logFile,
        "[".date('Y-m-d H:i:s')."] Outbound execution completed.\n",
        FILE_APPEND | LOCK_EX
    );
} catch (Throwable $e) {
    file_put_contents($logFile,
        "[".date('Y-m-d H:i:s')."] ERROR running outbound: ".$e->getMessage()."\n",
        FILE_APPEND | LOCK_EX
    );
}