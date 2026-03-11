## v1.5-feature (2026-03-11)
- Add hourly cron wrapper (pi/cron_hourly.php) to guarantee pipeline heartbeat.
- Add Task Scheduler XML (	asks/run_hourly_sms.xml) to run outbound hourly.
- Improve diagnostics for q1a + reminder window checks.
## v1.5-feature (2026-03-11)
- Add hourly cron wrapper (pi/cron_hourly.php) to guarantee pipeline heartbeat.
- Provide Task Scheduler XML (	asks/run_hourly_sms.xml) to run the cron hourly.
- Refine diagnostics and reminder window checks.
## v1.4-feature (2026-03-11)
- Diagnostics refined; reminder window checks consolidated.
## v1.3-feature (2026-03-11)
- FireText inbound: improved rate-limiting and diagnostics.
- JWT auth: restored use-existing-token-first behavior for SMS Works.
## v1.2 - 2025-10-01
feat(outbound): 3h one-time reminders (window-aware); HELP rate-limit 60m; invalid auto-reply max 1 per day per instance

## v1.1 - 2025-10-01
feat(schedulers): always print summary; add ?dry_run and ?verbose; auto-create logs dir; rotate logs

## v1.0 - 2025-10-01
feat(inbound): instant HELP auto-reply, strict 1..10 or 0 or HELP validation, async trigger

## v0.9 - 2025-10-01
fix(outbound): SMS Works branch uses current_sender_id for sender

## v0.8 - 2025-10-01
feat(config): add per-provider sender IDs and VMNs plus helper current_sender_id for provider switching

## v0.7 - 2025-10-01
feat(inbound-ft): add provider-agnostic inbound for FireText (validation, HELP, 0=opt-out, async trigger)

## v0.6 - 2025-10-01
feat(outbound): add 07:00 guard for q1a and AUTO-HEAL 07:00-12:00 window with summary logging

## v0.5 - 2025-10-01
feat(schedulers): add nightly instance creator and assessment date backfill

## v0.4 - 2025-10-01
feat(inbound): minimal deterministic inbound handler (RID Day q-code)

## v0.3 - 2025-10-01
feat(outbound): initial SMS outbound script (baseline sending via provider)

## v0.2 - 2025-10-01
feat(config): initial baseline configuration for REDCap and SMS Works

## v0.1 - 2025-10-01
chore: initial project skeleton (.gitignore, README, structure)


## v1.5.1 (2026-03-11)
- Update change log.


