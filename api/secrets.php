<?php
/**
 * secure/secrets.php
 * PRIVATE SECRETS — Move file to secrets.php and replace placeholders with real values.
 * All sensitive environment-specific secrets go here.
 */

/* ──────────────────────────────
 * REDCap Credentials
 * ────────────────────────────── */
define('REDCAP_API_URL_SECRET',   'https://redcap_url_XXXXXXXXX'); 
define('REDCAP_API_TOKEN_SECRET', 'XXXXXXXXXXXXXXXXXXXXXXXXXXXX');

/* ──────────────────────────────
 * SMS Works Credentials
 * ────────────────────────────── */
define('SMSW_API_KEY',     'xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx');
define('SMSW_API_SECRET',  'xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx');
define('SMSW_STATIC_JWT',  '');  // (optional) paste JWT here if pre-generated

/* ──────────────────────────────
 * FireText Credentials
 * ────────────────────────────── */
define('FIRETEXT_API_KEY', 'xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx');

/* ──────────────────────────────
 * Numeric VMNs (reply numbers)
 * ────────────────────────────── */
define('SMSW_REPLY_NUMBER_SECRET',     '4478XXXXXXX');  // Replace with real VMN
define('FIRETEXT_REPLY_NUMBER_SECRET', '44786XXXXXX');  // Replace with real VMN

/* ──────────────────────────────
 * Optional Proxies (set to null if unused)
 * ────────────────────────────── */
define('PROXY_HTTP',  null);
define('PROXY_HTTPS', null);