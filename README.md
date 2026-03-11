# REDCap SMS Module API
### One‑way and Two‑way SMS sending (SMS Works / FireText)

Automates daily participant follow‑up messaging for REDCap projects. The module creates daily repeating instances, sends questions (q1a→q5b), ingests replies, saves answers back to REDCap, and drives the sequence automatically with reminders and morning auto‑heal.

---

## 1) Ultra‑Simple Flow (5 steps)

1. **REDCap baseline saved → new follow‑up instance created**  
   *(key baseline fields: `mob_number`, `date_baseline`)*
2. **DET triggers** → calls `api/send_outbound.php`
3. **System sends SMS** via configured provider (SMS Works / FireText)
4. **Participant replies** → provider posts to `api/send_inbound.php`
5. **PHP saves answer & sends next question** until **q5a** is completed for the day

---

## 2) Features (at a glance)

| Area                  | What it does                                                                 |
|-----------------------|------------------------------------------------------------------------------|
| One‑way sending       | Sends daily questions and reminders                                         |
| Two‑way messaging     | Parses inbound (1–10, HELP, STOP), saves answers, advances sequence         |
| Auto‑heal             | Morning window catch‑up for missed q1a                                      |
| Reminders             | 3‑hour default reminder; gated by time window                               |
| DLR handling          | Delivery receipts update provider msg ID and sent/delivered/failed status   |
| Scheduling            | Nightly instance creation + hourly heartbeat to guarantee runs              |
| Secrets hygiene       | All credentials live in `secure/secrets.php` (git‑ignored)                  |
| Provider‑agnostic     | Works with SMS Works or FireText; select via `$PROVIDER` in `config.php`    |

---

## 3) Repository Map

| Path / File                          | Purpose |
|-------------------------------------|---------|
| `api/config.php`                    | Central configuration (no secrets) |
| `secure/secrets.php`                | **git‑ignored** secrets: REDCap token/URL, provider keys, VMNs, (optional) static JWT |
| `api/send_outbound.php`             | Outbound engine: evaluates q1a window, reminders, auto‑heal; sends SMS |
| `api/send_inbound.php`              | Inbound parser: validates/save reply; re‑invokes outbound to continue sequence |
| `api/scheduler_tomorrow.php`        | Creates tomorrow’s repeating instances and assessment dates |
| `api/cron_hourly.php`               | Hourly heartbeat wrapper (ensures pipeline runs even if DET/alerts fail) |
| `api/dlr_smsworks.php` (etc.)       | Provider delivery‑receipt callbacks → update REDCap status fields |
| `tasks/run_hourly_sms.xml`          | Windows Task Scheduler definition (import to run hourly) |
| `logs/`                             | Runtime logs (`outbound.log`, `inbound.log`, `scheduler_tomorrow.log`, `dlr_*.log`, `cron_hourly.log`) |

> **.gitignore should include:**  
> `/secure/`, `/logs/`, and any other environment‑specific outputs.

---

## 4) Quick Setup

### 4.1 Secrets (create once)
Create `secure/secrets.php` (**not** committed) with **only** these constant names:
```php
<?php
define('REDCAP_API_URL_SECRET',   'https://your-redcap.example/api/');
define('REDCAP_API_TOKEN_SECRET', 'XXXXXXXXXXXXXXXXXXXXXXXXXXXX');

define('SMSW_API_KEY_SECRET',     'xxxxxxxxxxxxxxxxxxxxxxxxxxxx');
define('SMSW_API_SECRET_SECRET',  'yyyyyyyyyyyyyyyyyyyyyyyyyyyy');
// Optional static JWT (bypass live generation):
define('SMSW_STATIC_JWT_SECRET',  ''); // or 'JWT eyJ...'

define('FIRETEXT_API_KEY_SECRET', 'zzzzzzzzzzzzzzzzzzzzzzzzzz');

define('SMSW_REPLY_NUMBER_SECRET',      '4478XXXXXXX');
define('FIRETEXT_REPLY_NUMBER_SECRET',  '44786XXXXXX');
define('SMSW_VMNS_SENDER_SECRET',       '4478XXXXXXX');
define('FIRETEXT_VMNS_SENDER_SECRET',   '44786XXXXXX');

define('CA_CERT_PATH_SECRET', null);
define('PROXY_HTTP_SECRET',   null);
define('PROXY_HTTPS_SECRET',  null);

$PROVIDER = getenv('SMS_PROVIDER') ?: 'smsworks'; // or 'firetext'
$TIMEZONE = 'Europe/London';

$BASELINE_EVENT        = 'baseline_arm_1';
$FOLLOWUP_EVENT        = 'followup__1_30_day_arm_1';
$FOLLOWUP_REPEAT_INSTR = 'goal_setting_assessments';

// q1a window & auto‑heal window
define('Q1A_GUARD_START_HOUR', 7);
define('Q1A_GUARD_END_HOUR',   21);
define('AUTO_HEAL_WINDOW_START_HOUR', 7);
define('AUTO_HEAL_WINDOW_END_HOUR',   21);

// Reminders & HELP
define('REMINDER_ENABLED', true);
define('REMINDER_SECONDS', 3*3600);
define('REMINDER_WINDOW_START_HOUR', 8);
define('REMINDER_WINDOW_END_HOUR',   21);
define('HELP_AUTOREPLY_ENABLED', true);
define('HELP_RATE_LIMIT_MINUTES', 60);