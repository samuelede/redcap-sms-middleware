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