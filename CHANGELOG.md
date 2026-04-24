## v0.6.3 (2026-04-25)

### Changed
- Moved responsibility for populating the first daily follow‑up question (`q1a`) from outbound logic to the scheduler, making instance creation the single source of truth for question text.
- Introduced a configurable template for daily `q1a` SMS text, allowing message structure changes without code modifications.
- Updated outbound processing so `q1a` is treated as a normal per‑day follow‑up question, rather than a baseline‑only special case.
- Enforced a time‑of‑day guard for `q1a` delivery in outbound processing, ensuring the first daily question is not sent before the configured start hour.

### Fixed
- Fixed an issue where follow‑up instances had empty `q1a` text due to reliance on REDCap `@SETVALUE`, which does not run for API‑created repeating instruments.
- Prevented follow‑up days from being skipped when `q1a` text was missing or incorrectly treated as baseline‑only.
- Eliminated premature sending of the first daily question outside the allowed time window after scheduler runs.

### Removed
- Removed baseline‑only `q1a` handling and special‑case send logic from outbound processing.
- Deprecated reliance on baseline `q1aa` text for follow‑up SMS delivery.
- Removed AUTO‑HEAL logic from outbound in favour of scheduler‑based data preparation and backfilling.

### Behavioural Notes
- The scheduler now ensures all follow‑up instances have complete question text before outbound evaluation.
- Outbound processing only sends messages when both eligibility and time‑of‑day conditions are met; cron automatically sends the first daily question once the time guard is satisfied.
- Non‑`q1a` follow‑up questions continue to progress immediately after valid replies, independent of time‑of‑day restrictions.

### Architecture
- Clarified separation of responsibilities:
  - Scheduler: instance creation and data correctness.
  - Outbound: delivery policy, sequencing, reminders, and opt‑out handling.

## v0.6.2 (2026-04-24)
### Added
- Introduced a secure outbound trigger (`trigger_outbound.php`) to invoke outbound processing immediately after inbound replies, decoupling user progression from cron timing.
- Added shared-secret authentication for outbound triggering to prevent unauthorised execution.
- Enabled inbound replies to initiate outbound evaluation within seconds, without requiring direct HTTP access to `send_outbound.php`.

### Changed
- Clarified and enforced responsibility boundaries:
  - Inbound handles data capture and triggers outbound evaluation.
  - Outbound retains full authority over eligibility and sequencing decisions.
- Preserved existing AUTO-HEAL and send-window behaviour for day-entry questions (`q1a`), while allowing non–day-entry questions to progress outside the send window when eligible.
- Hardened production configuration by blocking direct HTTP access to outbound and scheduler scripts, while permitting controlled triggering via a dedicated endpoint.

### Fixed
- Prevented premature population of follow-up answer fields when corresponding questions were not yet sent.
- Ensured inbound reply handling only records answers for questions that have actually been delivered (validated via provider message IDs).
- Eliminated dependency on cron frequency for outbound execution when immediate progression is possible.

### Behavioural Notes
- Immediate outbound triggering does not guarantee an SMS is sent; outbound logic may correctly decide that no eligible next question exists at that moment.
- Cron remains responsible for periodic re-evaluation and sending when eligibility conditions change.
- Day-entry questions (`q1a`) continue to respect defined send windows and are never sent overnight.

### Security
- Restricted outbound execution to CLI and authenticated internal triggers only.
- Maintained IP safelisting and POST-only enforcement for SMS Works delivery-report webhooks.

## v0.6.1 (2026-04-23)
### Fixed
- Prevented baseline question `q1a` (text sourced from `q1aa`) from being re-sent on follow-up days beyond Day 1.
- Corrected outbound progression so baseline-only questions are auto-skipped for advancement on Day > 1 and do not block subsequent questions.
- Fixed reminder logic so reminders are sent based on the last **sent unanswered question**, rather than the next candidate question when that candidate is suppressed.
- Ensured reminders are sent for all questions that were actually delivered (including `q1a` on Day 1) when no response is received within the configured reminder interval.
- Prevented reminders for baseline-only questions (`q1a`) from firing on Day 2+.
- Eliminated instance drift issues caused by calendar-based logic, ensuring outbound, inbound, and scheduler logic consistently operate on the correct follow-up instance.

### Changed
- Clarified outbound semantics between **progression logic** and **reminder logic**, making them independent but consistent.
- Reinforced the rule that baseline questions are day-scoped (Day 1 only), while reminders apply only to questions that were actually sent.

### Operational Notes
- These fixes do not retroactively alter previously created instances or sent messages.
- All changes are backward-compatible and apply to new outbound runs.

### Security
- Hardened production delivery-report webhook by restricting access to The SMS Works’ verified IP safelist at the IIS level.

## v0.6.0 (2026-04-22)
### Added
- Automatic completion of follow-up repeating instrument when all daily answer fields are populated.
- Support for treating response code `666` as a valid “answered” state for progression and completion.
- Deterministic inbound handling that maps replies to the earliest unanswered question for the day.

### Fixed
- Inbound SMS handling stability after outbound send-loop refactor.
- Incorrect skipping or silent rejection of valid numeric replies.
- Outbound sequencing issues caused by unconditional loop continues.
- Advancement logic so follow-up questions send correctly after valid responses.

### Changed
- Clarified responsibility boundaries:
  - Inbound: validate and record responses only.
  - Outbound: owns sequencing, timing, and message sending.
- Removed inbound dependency on outbound provider IDs and sent-state.

## v0.5.6 (2026-04-21)
### Changed
- Clarified reminder timing wording in cron_hourly to reflect 3-day (hours-based) logic
- Clarified inbound vs delivery report webhook separation for SMS Works

## v0.5.5 (2026-04-20)
- Align baseline handling to Day 0 per study protocol
- Suppress all outbound SMS on baseline day (Day 0)
- Ensure first assessment SMS is sent on Day 1 only
- Make inbound and outbound day arithmetic consistent
- Preserve time-based reminder logic (3 days after first send)
- Improve robustness for late replies and unanswered question detection

## v0.5.4 (2026-04-20)
- Add environment-specific IIS configuration templates:
  - web.config.dev for development/debug access
  - web.config.prod for production hardening
- Document deployment workflow using template-based web.config replacement
- Prevent accidental commit of active web.config

## v0.5.3 (2026-04-14)
- Fix SMS Works JWT handling (store raw token, apply single JWT prefix at send time).
- Normalize UK MSISDNs to E.164 format for SMS Works.
- Stabilise outbound SEND loop execution under IIS + PHP FastCGI.
- Confirm authorised VMN usage and inbound/outbound parity.
- Improve diagnostics for AUTO-HEAL and SEND LOOP eligibility.

## v0.5.2-feature (2026-03-11)
- Add hourly cron wrapper (api/cron_hourly.php) to guarantee pipeline heartbeat.
- Add Task Scheduler XML (	asks/run_hourly_sms.xml) to run outbound hourly.
- Improve diagnostics for q1a + reminder window checks.

## v0.5.1-feature (2026-03-11)
- Improvements + documentations

## v0.5-feature (2026-03-11)
- Add hourly cron wrapper (api/cron_hourly.php) to guarantee pipeline heartbeat.
- Provide Task Scheduler XML (	asks/run_hourly_sms.xml) to run the cron hourly.
- Refine diagnostics and reminder window checks.

## v0.4-feature (2026-03-11)
- Diagnostics refined; reminder window checks consolidated.

## v0.3-feature (2026-03-11)
- FireText inbound: improved rate-limiting and diagnostics.
- JWT auth: restored use-existing-token-first behavior for SMS Works.

## v0.2 - 2025-10-01
feat(outbound): 3h one-time reminders (window-aware); HELP rate-limit 60m; invalid auto-reply max 1 per day per instance

## v0.1.1 - 2025-10-01
feat(schedulers): always print summary; add ?dry_run and ?verbose; auto-create logs dir; rotate logs

## v0.1.0 - 2025-10-01
feat(inbound): instant HELP auto-reply, strict 1..10 or 0 or HELP validation, async trigger

## v0.0.9 - 2025-10-01
fix(outbound): SMS Works branch uses current_sender_id for sender

## v0.0.8 - 2025-10-01
feat(config): add per-provider sender IDs and VMNs plus helper current_sender_id for provider switching

## v0.0.7 - 2025-10-01
feat(inbound-ft): add provider-agnostic inbound for FireText (validation, HELP, 0=opt-out, async trigger)

## v0.0.6 - 2025-10-01
feat(outbound): add 07:00 guard for q1a and AUTO-HEAL 07:00-12:00 window with summary logging

## v0.0.5 - 2025-10-01
feat(schedulers): add nightly instance creator and assessment date backfill

## v0.0.4 - 2025-10-01
feat(inbound): minimal deterministic inbound handler (RID Day q-code)

## v0.0.3 - 2025-10-01
feat(outbound): initial SMS outbound script (baseline sending via provider)

## v0.0.2 - 2025-10-01
feat(config): initial baseline configuration for REDCap and SMS Works

## v0.0.1 - 2025-10-01
chore: initial project skeleton (.gitignore, README, structure)