<?php
/**
 * trigger_outbound.php
 *
 * Minimal, safe HTTP trigger for send_outbound.php
 */

require_once __DIR__ . '/config.php';

// POST only
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method Not Allowed');
}

// Simple shared secret check
$provided = $_POST['secret'] ?? '';
if ($provided !== OUTBOUND_TRIGGER_SECRET) {
    http_response_code(403);
    exit('Forbidden');
}

// Call outbound via CLI-style execution
// ------------------------------------------------------------
// Mark this run as triggered by an inbound reply
// ------------------------------------------------------------
putenv('OUTBOUND_TRIGGERED=1');

// Run outbound via CLI-style execution
passthru(PHP_BINARY . ' ' . escapeshellarg(__DIR__ . '/send_outbound.php'));

http_response_code(200);
echo 'OK';