<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Console Routes / Artisan Commands
|--------------------------------------------------------------------------
*/

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Scheduled Commands — Unclaimed Document Policy
|--------------------------------------------------------------------------
|
| BUG FIX (client-side auto-forfeit race condition, found while
| investigating QA #12):
|
| useStaffDashboard.js used to compute "90+ days old" client-side and
| fire a real forfeit write directly from the dashboard's poll — on
| EVERY staff member's browser, every 30s, for every stale request. Two
| distinct problems: (1) N dashboards open == N racing writes for the
| same request with no lock, and (2) it used the wrong clock entirely
| (requested_at — creation time — instead of the most recent transition
| into ReadyToClaim), a silently-diverging definition of the same
| business rule this command already gets right.
|
| That client-side stand-in only existed because this job ran once a
| day, so staff would otherwise see a stale ReadyToClaim request for up
| to ~24h after it should have been forfeited. Rather than patch the
| symptom (add a lock, fix the date math client-side, keep two
| implementations in sync forever), the fix is to make the one correct,
| already-transactional, already-audited, already-cache-invalidated
| implementation (this command) run often enough that no client-side
| stand-in is needed at all — exactly the precedent already set below
| by role-assignments:expire for the identical "daily cadence leaves
| stale status visible too long" problem.
|
| Order still matters relative to the other 08:xx jobs:
|   08:00  (kept, redundant with hourly() below, harmless)
|   08:05  SendUnclaimedReminders  — send 7-day warnings for the rest.
|
| withoutOverlapping() — prevents a slow DB run from spawning a duplicate.
| runInBackground()    — keeps the scheduler process non-blocking.
| appendOutputTo()     — tails in storage/logs/scheduler.log.
|--------------------------------------------------------------------------
*/

Schedule::command('notifications:shred-expired-requests')
    ->hourly()
    ->withoutOverlapping()
    ->runInBackground()
    ->appendOutputTo(storage_path('logs/scheduler.log'));

Schedule::command('notifications:send-unclaimed-reminders')
    ->dailyAt('08:05')
    ->withoutOverlapping()
    ->runInBackground()
    ->appendOutputTo(storage_path('logs/scheduler.log'));

/*
|--------------------------------------------------------------------------
| Scheduled Commands — Announcement Archive Policy
|--------------------------------------------------------------------------
|
| 08:10  AutoDisableExpiredAnnouncements — disable announcements whose
|        end_date has passed, so they become eligible for archiving
|        without staff having to remember to flip the toggle. Runs after
|        the document-request jobs above just to keep the daily 08:xx
|        block together; there's no ordering dependency between them.
|--------------------------------------------------------------------------
*/

Schedule::command('announcements:auto-disable-expired')
    ->dailyAt('08:10')
    ->withoutOverlapping()
    ->runInBackground()
    ->appendOutputTo(storage_path('logs/scheduler.log'));

/*
|--------------------------------------------------------------------------
| Scheduled Commands — Admin/Staff Provisioning Expiry
|--------------------------------------------------------------------------
|
| 08:15  ExpireStaleProvisioning — flip any SystemUser still
|        'Pending Activation' past pending_expires_at (14 days) to
|        'Expired', and any access_requests row still 'Requested' past
|        expires_at (7 days) to 'Expired'. Kept in the same 08:xx block as
|        the jobs above; given 08:10 (not 08:05) so it never lands on the
|        same minute as announcements:auto-disable-expired.
|--------------------------------------------------------------------------
*/

Schedule::command('provisioning:expire-stale')
    ->dailyAt('08:15')
    ->withoutOverlapping()
    ->runInBackground()
    ->appendOutputTo(storage_path('logs/scheduler.log'));

/*
|--------------------------------------------------------------------------
| Scheduled Commands — Data Retention & Disposal Policy (§3.3)
|--------------------------------------------------------------------------
|
| 08:20  EscalateStaleDeficiencyNotices — flag any open Deficiency
|        Notice past its 30-day compliance window and notify Registrar
|        Admins for review (extend / abandon via Withdrawn / close via
|        ClosedUnableToProcess — see that command's docblock for why it
|        only escalates and never auto-decides). Given the free 08:20
|        slot between ExpireStaleProvisioning (08:15) and
|        security-events:prune (08:25).
|--------------------------------------------------------------------------
*/

Schedule::command('notifications:escalate-stale-deficiency-notices')
    ->dailyAt('08:20')
    ->withoutOverlapping()
    ->runInBackground()
    ->appendOutputTo(storage_path('logs/scheduler.log'));

// BUG FIX (RIS-PROCESS-BUGS #5 — "Role Status Remains 'Active' Past
// Expiration Date and Time"):
//
// This was previously ->dailyAt('08:20'), so an expired role assignment's
// Sanctum tokens stayed alive — and its audit-log/ROLE_EXPIRED entry stayed
// unwritten — for up to ~24h after expires_at elapsed. The *display* half
// of this bug (the Manage Roles modal showing a stale "Active" badge) is
// fixed independently and immediately via RoleAssignment::effectiveStatus()
// / RoleAssignmentResource (no longer depends on this job's cadence at
// all). This schedule change addresses the other half: how long an
// expired grant's actual SESSION (Sanctum tokens) and audit trail lag
// behind reality.
//
// Hourly (not, say, every minute) is a deliberate balance: expires_at
// values in practice are set to day/term boundaries, not second-level
// precision, so sub-hour freshness has no real-world payoff here, while
// hourly keeps the query cheap (indexed, typically an empty or tiny result
// set — see RoleAssignment::scopeDueToExpire()) and keeps this job well
// clear of the other 08:xx jobs' overlap window. withoutOverlapping()
// still guards against a slow run stacking with the next tick.
Schedule::command('role-assignments:expire')
    ->hourly()
    ->withoutOverlapping()
    ->runInBackground()
    ->appendOutputTo(storage_path('logs/scheduler.log'));

/*
|--------------------------------------------------------------------------
| Scheduled Commands — Audit Log Chain Integrity
|--------------------------------------------------------------------------
|
| Nightly: recompute and verify the entire audit_logs hash chain (see
| AuditLogger::log() / AuditLog::booted() / the audit:verify command
| itself). Not called out explicitly as a scheduled job in the original
| spec — it only asked for the command to exist "for CI/cron alerting" —
| but a tamper-evidence mechanism nobody is checking is a mechanism that
| catches tampering only when someone happens to remember to run it by
| hand. Cheap (single ordered read of the table) and high-value to run
| unattended; wire the non-zero exit code into your monitoring/alerting
| the same way break-glass:test already implies below.
|--------------------------------------------------------------------------
*/

Schedule::command('audit:verify')
    ->dailyAt('03:00')
    ->withoutOverlapping()
    ->runInBackground()
    ->appendOutputTo(storage_path('logs/scheduler.log'));

/*
|--------------------------------------------------------------------------
| Scheduled Commands — Break-Glass Access Health Check
|--------------------------------------------------------------------------
|
| Weekly: verify every break-glass (local-auth) account is still
| correctly configured (Activated, has a password hash, Super Admin
| role) BEFORE an IdP outage is the first time anyone finds out one
| has drifted (e.g. deactivated, or somehow enabled on a non-super-admin
| account). Exits non-zero on failure so this can be wired into
| external monitoring/alerting on top of the scheduler log below.
|--------------------------------------------------------------------------
*/

Schedule::command('break-glass:test')
    ->weekly()
    ->withoutOverlapping()
    ->runInBackground()
    ->appendOutputTo(storage_path('logs/scheduler.log'));

/*
|--------------------------------------------------------------------------
| Scheduled Commands — Security Events Retention (Phase 3h)
|--------------------------------------------------------------------------
|
| Daily: prune security_events rows older than
| config('security_events.retention_days'). Deliberately independent of
| audit:verify above — audit_logs stays permanent/untouched; only this
| operational/security-signal table is pruned. Given the same 08:xx
| slot as the other daily hygiene jobs; 08:25 keeps it clear of every
| other job's window above.
|--------------------------------------------------------------------------
*/

Schedule::command('security-events:prune')
    ->dailyAt('08:25')
    ->withoutOverlapping()
    ->runInBackground()
    ->appendOutputTo(storage_path('logs/scheduler.log'));

/*
|--------------------------------------------------------------------------
| Scheduled Commands — Job-Health Monitoring Retention
|--------------------------------------------------------------------------
|
| Daily: prune job_run_logs rows older than
| config('job_monitoring.retention_days') — this table's own retention
| sweep, same shape as security-events:prune above (which it runs
| directly after). 08:30 keeps it clear of every other 08:xx job's
| window. See PruneJobRunLogs and the create_job_run_logs_table
| migration for why this table exists.
|--------------------------------------------------------------------------
*/

Schedule::command('job-run-logs:prune')
    ->dailyAt('08:30')
    ->withoutOverlapping()
    ->runInBackground()
    ->appendOutputTo(storage_path('logs/scheduler.log'));