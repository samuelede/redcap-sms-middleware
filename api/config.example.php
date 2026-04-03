<?php
/**
 * config.example.php
 * 
 * Copy this file to config.php and update the values for your environment.
 * Never commit config.php to version control.
 * All credentials (API tokens, provider keys) belong in secure/secrets.php.
 */

// SMS provider selection: 'smsworks' or 'firetext'
define('PROVIDER', 'smsworks');

// REDCap field names for participant identification
define('MOBILE_FIELD', 'mob_number');
define('BASELINE_DATE_FIELD', 'date_baseline');

// Question sequence field names (q1a through q5b)
define('QUESTION_FIELDS', [
    'q1a', 'q1b', 'q2a', 'q2b',
    'q3a', 'q3b', 'q4a', 'q4b',
    'q5a', 'q5b'
]);

// Messaging time windows (24-hour format)
define('SEND_WINDOW_START', '08:00');
define('SEND_WINDOW_END',   '21:00');
define('REMINDER_DELAY_HOURS', 3);

// REDCap instrument and event names
define('FOLLOWUP_INSTRUMENT', 'daily_followup');
define('BASELINE_EVENT',      'baseline_arm_1');
define('FOLLOWUP_EVENT',      'followup_arm_1');

// Logging
define('LOG_DIR', __DIR__ . '/../logs/');
define('LOG_LEVEL', 'INFO'); // DEBUG, INFO, WARNING, ERROR