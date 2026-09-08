<?php

use App\Models\AccessRequest;
use App\Models\AuditLog;
use App\Models\JobRunLog;
use App\Models\Policy;
use App\Models\RoleAssignment;
use App\Models\SystemUser;
use App\Models\UnmatchedCashierItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

// SuperAdminAnalyticsController caches every panel (see its class
// docblock — Redis in production, the 'array' driver in testing). The
// 'array' store lives in memory for the lifetime of the PHP process, and
// Pest runs every test in this file in the same process — RefreshDatabase
// rolls back the database between tests, but does nothing to that cache.
// Without this, whichever test hits a given endpoint+params combination
// FIRST permanently "wins" that cache entry for every later test in the
// file, regardless of what data that later test actually seeded. Flushing
// the 'analytics' tag before each test keeps this file's tests isolated
// from each other the same way the DB transaction already does.
beforeEach(fn () => Cache::tags(['analytics'])->flush());

// ── Helpers ──────────────────────────────────────────────────────────────────

function saMakeUser(int $roleId): SystemUser
{
    $user = SystemUser::factory()->create(['role_id' => $roleId, 'status' => 'Activated']);
    Sanctum::actingAs($user);
    return $user;
}

/**
 * Writes an audit_logs row directly rather than through AuditLogger — the
 * hash chain isn't what's under test here, and prev_hash/hash are nullable
 * at the DB level specifically to allow this kind of direct insert.
 */
function saWriteAuditLog(string $action, array $overrides = []): AuditLog
{
    return AuditLog::create(array_merge([
        'user_id'    => null,
        'email'      => 'system@ris.local',
        'role_name'  => 'super_admin',
        'action'     => $action,
        'created_at' => now(),
    ], $overrides));
}

/**
 * No AccessRequestFactory exists in this codebase (see
 * AccessRequestServiceTest, which also builds rows via AccessRequest::
 * create() directly) — this mirrors that same pattern with sensible
 * defaults for throughput-metric tests, which only care about status/
 * created_at/reviewed_at, not the target-identity fields.
 *
 * Deliberately creates a fresh `requested_by` SystemUser on every call
 * rather than memoizing one in a `static` local — RefreshDatabase rolls
 * back the DB after each test, but a PHP `static` variable would keep
 * pointing at the now-rolled-back row's id in the next test (Pest runs
 * every test in this file in one process), producing a foreign-key
 * violation on insert. One extra factory call per access request is
 * cheap; a flaky FK error chasing a stale in-memory reference is not.
 */
function saAccessRequest(array $overrides = []): AccessRequest
{
    $submitter = SystemUser::factory()->create(['role_id' => SystemUser::ROLE_ADMIN]);

    return AccessRequest::create(array_merge([
        'requested_by'      => $submitter->user_id,
        'target_email'      => 'target' . uniqid() . '@example.com',
        'target_first_name' => 'Test',
        'target_last_name'  => 'Target',
        'requested_role_id' => SystemUser::ROLE_ADMIN,
        'justification'     => 'Regression fixture.',
        'status'            => AccessRequest::STATUS_REQUESTED,
        'expires_at'        => now()->addDays(7),
    ], $overrides));
}

/**
 * Writes a job_run_logs row directly, the same shape LogsJobRun's
 * startJobRun()/finishJobRun() would have produced for a real command
 * invocation. $startedAgo lets a test place the run at an arbitrary
 * point in the past — the whole point of this panel is "how long ago
 * did this last run", so every test below needs to control that
 * precisely rather than relying on "just now".
 */
function saWriteJobRun(string $jobName, string $status, Carbon $startedAgo, ?string $errorMessage = null): JobRunLog
{
    $finishedAt = in_array($status, [JobRunLog::STATUS_SUCCESS, JobRunLog::STATUS_FAILED], true)
        ? $startedAgo->copy()->addSecond()
        : null;

    return JobRunLog::create([
        'job_name'      => $jobName,
        'status'        => $status,
        'started_at'    => $startedAgo,
        'finished_at'   => $finishedAt,
        'duration_ms'   => $finishedAt ? 1000 : null,
        'rows_affected' => $status === JobRunLog::STATUS_SUCCESS ? 0 : null,
        'error_message' => $errorMessage,
    ]);
}

// ═════════════════════════════════════════════════════════════════════════════
// Access control
// ═════════════════════════════════════════════════════════════════════════════

test('a plain admin cannot reach system-analytics endpoints', function () {
    saMakeUser(SystemUser::ROLE_ADMIN);

    $this->getJson('/api/system-analytics/admin-roster-health')->assertForbidden();
    $this->getJson('/api/system-analytics/access-request-throughput')->assertForbidden();
    $this->getJson('/api/system-analytics/cashier-verification-health')->assertForbidden();
});

test('a student cannot reach system-analytics endpoints', function () {
    saMakeUser(SystemUser::ROLE_STUDENT);

    $this->getJson('/api/system-analytics/admin-roster-health')->assertForbidden();
});

test('an unauthenticated request is rejected', function () {
    $this->getJson('/api/system-analytics/admin-roster-health')->assertUnauthorized();
});

test('a super admin can reach every system-analytics endpoint', function () {
    saMakeUser(SystemUser::ROLE_SUPER_ADMIN);

    $this->getJson('/api/system-analytics/admin-roster-health')->assertOk();
    $this->getJson('/api/system-analytics/access-request-throughput')->assertOk();
    $this->getJson('/api/system-analytics/cashier-verification-health')->assertOk();
});

// ═════════════════════════════════════════════════════════════════════════════
// adminRosterHealth()
// ═════════════════════════════════════════════════════════════════════════════

test('role breakdown only counts admin/super-admin role assignments, not student/alumni baseline rows', function () {
    saMakeUser(SystemUser::ROLE_SUPER_ADMIN);

    // Student baseline row — must NOT leak into the admin/super-admin
    // breakdown (role_assignments holds one of these per user, see the
    // backfill migration referenced in SuperAdminAnalyticsService).
    $student = SystemUser::factory()->create(['role_id' => SystemUser::ROLE_STUDENT, 'status' => 'Activated']);
    RoleAssignment::create([
        'user_id' => $student->user_id,
        'role_id' => SystemUser::ROLE_STUDENT,
        'status'  => RoleAssignment::STATUS_ACTIVE,
    ]);

    $admin = SystemUser::factory()->create(['role_id' => SystemUser::ROLE_ADMIN, 'status' => 'Activated']);
    RoleAssignment::create([
        'user_id' => $admin->user_id,
        'role_id' => SystemUser::ROLE_ADMIN,
        'status'  => RoleAssignment::STATUS_ACTIVE,
    ]);

    $response = $this->getJson('/api/system-analytics/admin-roster-health')->assertOk();

    $adminRow = collect($response->json('role_breakdown'))->firstWhere('role_id', SystemUser::ROLE_ADMIN);
    expect($adminRow['active_count'])->toBe(1);

    $studentRow = collect($response->json('role_breakdown'))->firstWhere('role_id', SystemUser::ROLE_STUDENT);
    expect($studentRow)->toBeNull();
});

test('an Active row whose expires_at has already elapsed is bucketed as due_to_expire, not active', function () {
    saMakeUser(SystemUser::ROLE_SUPER_ADMIN);

    $admin = SystemUser::factory()->create(['role_id' => SystemUser::ROLE_ADMIN, 'status' => 'Activated']);

    // Still status = Active in the DB (the daily sweep hasn't run) but
    // expires_at is in the past — must be reported as due_to_expire, not
    // active, mirroring RoleAssignment::scopeActive()'s live behavior.
    RoleAssignment::create([
        'user_id'    => $admin->user_id,
        'role_id'    => SystemUser::ROLE_ADMIN,
        'status'     => RoleAssignment::STATUS_ACTIVE,
        'expires_at' => now()->subHour(),
    ]);

    $response = $this->getJson('/api/system-analytics/admin-roster-health')->assertOk();
    $adminRow = collect($response->json('role_breakdown'))->firstWhere('role_id', SystemUser::ROLE_ADMIN);

    expect($adminRow['active_count'])->toBe(0);
    expect($adminRow['due_to_expire_count'])->toBe(1);
});

test('revoked and expired role assignments are counted in their own buckets', function () {
    saMakeUser(SystemUser::ROLE_SUPER_ADMIN);

    $revokedUser = SystemUser::factory()->create(['role_id' => SystemUser::ROLE_ADMIN]);
    RoleAssignment::create([
        'user_id' => $revokedUser->user_id,
        'role_id' => SystemUser::ROLE_ADMIN,
        'status'  => RoleAssignment::STATUS_REVOKED,
    ]);

    $expiredUser = SystemUser::factory()->create(['role_id' => SystemUser::ROLE_ADMIN]);
    RoleAssignment::create([
        'user_id' => $expiredUser->user_id,
        'role_id' => SystemUser::ROLE_ADMIN,
        'status'  => RoleAssignment::STATUS_EXPIRED,
    ]);

    $response = $this->getJson('/api/system-analytics/admin-roster-health')->assertOk();
    $adminRow = collect($response->json('role_breakdown'))->firstWhere('role_id', SystemUser::ROLE_ADMIN);

    expect($adminRow['revoked_count'])->toBe(1);
    expect($adminRow['expired_count'])->toBe(1);
});

test('pending activations are listed and flagged when expiring within 3 days', function () {
    saMakeUser(SystemUser::ROLE_SUPER_ADMIN);

    SystemUser::factory()->create([
        'role_id'            => SystemUser::ROLE_ADMIN,
        'status'             => 'Pending Activation',
        'pending_expires_at' => now()->addDay(),
    ]);

    SystemUser::factory()->create([
        'role_id'            => SystemUser::ROLE_ADMIN,
        'status'             => 'Pending Activation',
        'pending_expires_at' => now()->addDays(10),
    ]);

    $response = $this->getJson('/api/system-analytics/admin-roster-health')->assertOk();

    expect($response->json('pending_activations.total'))->toBe(2);
    expect($response->json('pending_activations.expiring_soon'))->toBe(1);
});

test('IDP sync failures older than 30 days are excluded from the count', function () {
    saMakeUser(SystemUser::ROLE_SUPER_ADMIN);

    saWriteAuditLog(AuditLog::ACTION_ADMIN_IDP_SYNC_FAILED, [
        'target_email' => 'recent@example.com',
        'created_at'   => now()->subDays(5),
    ]);
    saWriteAuditLog(AuditLog::ACTION_ADMIN_IDP_SYNC_FAILED, [
        'target_email' => 'old@example.com',
        'created_at'   => now()->subDays(45),
    ]);

    $response = $this->getJson('/api/system-analytics/admin-roster-health')->assertOk();

    expect($response->json('idp_sync_failures.count_last_30_days'))->toBe(1);
    expect($response->json('idp_sync_failures.recent.0.target_email'))->toBe('recent@example.com');
});

// ═════════════════════════════════════════════════════════════════════════════
// accessRequestThroughput()
// ═════════════════════════════════════════════════════════════════════════════

test('approval_rate is computed from fulfilled vs rejected, not the unused Approved status', function () {
    saMakeUser(SystemUser::ROLE_SUPER_ADMIN);

    // Mirrors AccessRequestService::approve(), which writes STATUS_FULFILLED
    // directly — STATUS_APPROVED is never actually persisted in practice.
    for ($i = 0; $i < 3; $i++) {
        saAccessRequest(['status' => AccessRequest::STATUS_FULFILLED, 'reviewed_at' => now()]);
    }
    saAccessRequest(['status' => AccessRequest::STATUS_REJECTED, 'reviewed_at' => now()]);
    for ($i = 0; $i < 2; $i++) {
        saAccessRequest(['status' => AccessRequest::STATUS_REQUESTED]);
    }

    $response = $this->getJson('/api/system-analytics/access-request-throughput?range=all')->assertOk();

    expect($response->json('total'))->toBe(6);
    expect($response->json('fulfilled'))->toBe(3);
    expect($response->json('rejected'))->toBe(1);
    // 3 / (3 + 1) = 75%. Compared with toEqual (loose ==), not toBe
    // (strict ===): PHP's json_encode() drops the trailing .0 from a
    // whole-number float unless JSON_PRESERVE_ZERO_FRACTION is set, which
    // Laravel's response()->json() doesn't set by default — so this
    // decodes back as the int 75, not the float 75.0. match_rate's 66.7
    // assertion elsewhere in this file doesn't hit this, since it isn't a
    // whole number.
    expect($response->json('approval_rate'))->toEqual(75.0);
});

test('avg_time_to_review_hours is null when nothing has been reviewed yet', function () {
    saMakeUser(SystemUser::ROLE_SUPER_ADMIN);

    saAccessRequest(['status' => AccessRequest::STATUS_REQUESTED, 'reviewed_at' => null]);

    $response = $this->getJson('/api/system-analytics/access-request-throughput?range=all')->assertOk();

    expect($response->json('avg_time_to_review_hours'))->toBeNull();
    expect($response->json('approval_rate'))->toBeNull();
});

// ═════════════════════════════════════════════════════════════════════════════
// cashierVerificationHealth()
// ═════════════════════════════════════════════════════════════════════════════

test('match rate is computed from cashier_verification audit entries', function () {
    saMakeUser(SystemUser::ROLE_SUPER_ADMIN);

    saWriteAuditLog(AuditLog::ACTION_CASHIER_VERIFICATION, ['metadata' => ['final_approved' => true]]);
    saWriteAuditLog(AuditLog::ACTION_CASHIER_VERIFICATION, ['metadata' => ['final_approved' => true]]);
    saWriteAuditLog(AuditLog::ACTION_CASHIER_VERIFICATION, ['metadata' => ['final_approved' => false]]);

    $response = $this->getJson('/api/system-analytics/cashier-verification-health?range=all')->assertOk();

    expect($response->json('total_attempts'))->toBe(3);
    expect($response->json('matched'))->toBe(2);
    expect($response->json('unmatched'))->toBe(1);
    // 2 / 3 = 66.7%
    expect($response->json('match_rate'))->toBe(66.7);
});

test('unresolved backlog counts only unmatched cashier items with no resolved_at', function () {
    saMakeUser(SystemUser::ROLE_SUPER_ADMIN);

    UnmatchedCashierItem::create([
        'raw_label'         => 'Transcript Fee',
        'normalised_label'  => 'transcript fee',
        'occurrence_count'  => 5,
        'first_seen_at'     => now(),
        'last_seen_at'      => now(),
    ]);
    UnmatchedCashierItem::create([
        'raw_label'         => 'Diploma Fee',
        'normalised_label'  => 'diploma fee',
        'occurrence_count'  => 1,
        'first_seen_at'     => now(),
        'last_seen_at'      => now(),
        'resolved_at'       => now(),
    ]);

    $response = $this->getJson('/api/system-analytics/cashier-verification-health?range=all')->assertOk();

    expect($response->json('unresolved_backlog'))->toBe(1);
    expect($response->json('top_backlog_items.0.raw_label'))->toBe('Transcript Fee');
});

// ═════════════════════════════════════════════════════════════════════════════
// scheduledJobsHealth() — Job-Health Monitoring
// ═════════════════════════════════════════════════════════════════════════════

test('a job with no rows at all is reported as never_run and counted in needs_attention', function () {
    saMakeUser(SystemUser::ROLE_SUPER_ADMIN);

    // Every other job in JobRunLog::JOBS gets a healthy row so only the
    // one under test drives needs_attention — otherwise this assertion
    // would be trivially true for the wrong reason (every job starts
    // out never_run before any fixture writes a row).
    foreach (JobRunLog::JOBS as $jobName => $label) {
        if ($jobName === 'audit:verify') {
            continue; // the one left never_run on purpose
        }
        saWriteJobRun($jobName, JobRunLog::STATUS_SUCCESS, now()->subMinute());
    }

    $response = $this->getJson('/api/system-analytics/scheduled-jobs-health')->assertOk();

    $job = collect($response->json('jobs'))->firstWhere('job_name', 'audit:verify');
    expect($job['status'])->toBe('never_run');
    expect($job['last_started_at'])->toBeNull();
    expect($response->json('needs_attention'))->toBe(1);
});

test('a run still sitting in running well past the stalled ceiling is reported as stalled, not running', function () {
    saMakeUser(SystemUser::ROLE_SUPER_ADMIN);

    // Hourly job, "started" 45 minutes ago and never closed out — past
    // scheduledJobsHealth()'s 30-minute stalled ceiling, and every one
    // of these commands normally finishes in well under a minute.
    saWriteJobRun('notifications:shred-expired-requests', JobRunLog::STATUS_RUNNING, now()->subMinutes(45));

    $response = $this->getJson('/api/system-analytics/scheduled-jobs-health')->assertOk();

    $job = collect($response->json('jobs'))->firstWhere('job_name', 'notifications:shred-expired-requests');
    expect($job['status'])->toBe('stalled');
});

test('a run still sitting in running within the stalled ceiling is reported as running, not stalled or overdue', function () {
    saMakeUser(SystemUser::ROLE_SUPER_ADMIN);

    // 10 minutes in — well under both the 30-minute stalled ceiling AND
    // this hourly job's 120-minute overdue threshold. A run genuinely
    // in progress right now must never be flagged as either.
    saWriteJobRun('role-assignments:expire', JobRunLog::STATUS_RUNNING, now()->subMinutes(10));

    $response = $this->getJson('/api/system-analytics/scheduled-jobs-health')->assertOk();

    $job = collect($response->json('jobs'))->firstWhere('job_name', 'role-assignments:expire');
    expect($job['status'])->toBe('running');
});

test('a failed run is reported as failed with its error message, even long after it started', function () {
    saMakeUser(SystemUser::ROLE_SUPER_ADMIN);

    // Started 5 hours ago — well past this hourly job's 120-minute
    // overdue threshold. A FAILED outcome must win over 'overdue': the
    // schedule clearly did fire (that's what "failed" means, as opposed
    // to no row at all), so reporting it as merely 'overdue' would hide
    // the more specific and more actionable signal.
    saWriteJobRun(
        'notifications:shred-expired-requests',
        JobRunLog::STATUS_FAILED,
        now()->subHours(5),
        'Connection refused: database unreachable.',
    );

    $response = $this->getJson('/api/system-analytics/scheduled-jobs-health')->assertOk();

    $job = collect($response->json('jobs'))->firstWhere('job_name', 'notifications:shred-expired-requests');
    expect($job['status'])->toBe('failed');
    expect($job['error_message'])->toBe('Connection refused: database unreachable.');
});

test('a successful run well within its expected interval is reported as success, not overdue', function () {
    saMakeUser(SystemUser::ROLE_SUPER_ADMIN);

    // Hourly job, ran 20 minutes ago — nowhere near its 120-minute
    // overdue threshold.
    saWriteJobRun('role-assignments:expire', JobRunLog::STATUS_SUCCESS, now()->subMinutes(20));

    $response = $this->getJson('/api/system-analytics/scheduled-jobs-health')->assertOk();

    $job = collect($response->json('jobs'))->firstWhere('job_name', 'role-assignments:expire');
    expect($job['status'])->toBe('success');
});

test('a successful run whose gap has exceeded its expected interval is reported as overdue', function () {
    // This is the exact production edge case that motivated the
    // 'overdue' status: the scheduler container going down across an
    // entire tick (e.g. a deploy) leaves the last row looking perfectly
    // healthy ('success') even though the job hasn't actually run since.
    // A 'stalled'-only check can never see this, because nothing is
    // stuck in 'running' — there's simply no newer row at all.
    saMakeUser(SystemUser::ROLE_SUPER_ADMIN);

    // Hourly job (120-minute threshold), last successful run 3 hours ago.
    saWriteJobRun('notifications:shred-expired-requests', JobRunLog::STATUS_SUCCESS, now()->subHours(3));

    $response = $this->getJson('/api/system-analytics/scheduled-jobs-health')->assertOk();

    $job = collect($response->json('jobs'))->firstWhere('job_name', 'notifications:shred-expired-requests');
    expect($job['status'])->toBe('overdue');
    expect($response->json('needs_attention'))->toBeGreaterThanOrEqual(1);
});

test('a daily job successful 20 hours ago is still success, not overdue, per its wider grace window', function () {
    saMakeUser(SystemUser::ROLE_SUPER_ADMIN);

    // Daily jobs get a 26-hour (1560-minute) threshold specifically so a
    // job that's merely running a few hours "late" within the same
    // calendar day doesn't false-positive.
    saWriteJobRun('notifications:send-unclaimed-reminders', JobRunLog::STATUS_SUCCESS, now()->subHours(20));

    $response = $this->getJson('/api/system-analytics/scheduled-jobs-health')->assertOk();

    $job = collect($response->json('jobs'))->firstWhere('job_name', 'notifications:send-unclaimed-reminders');
    expect($job['status'])->toBe('success');
});

test('a daily job successful 30 hours ago is overdue, past its 26-hour grace window', function () {
    saMakeUser(SystemUser::ROLE_SUPER_ADMIN);

    saWriteJobRun('notifications:send-unclaimed-reminders', JobRunLog::STATUS_SUCCESS, now()->subHours(30));

    $response = $this->getJson('/api/system-analytics/scheduled-jobs-health')->assertOk();

    $job = collect($response->json('jobs'))->firstWhere('job_name', 'notifications:send-unclaimed-reminders');
    expect($job['status'])->toBe('overdue');
});

test('a weekly job successful 5 days ago is still success, not overdue', function () {
    saMakeUser(SystemUser::ROLE_SUPER_ADMIN);

    // Weekly job, 8-day (11520-minute) threshold — 5 days in is well
    // within its normal cadence.
    saWriteJobRun('break-glass:test', JobRunLog::STATUS_SUCCESS, now()->subDays(5));

    $response = $this->getJson('/api/system-analytics/scheduled-jobs-health')->assertOk();

    $job = collect($response->json('jobs'))->firstWhere('job_name', 'break-glass:test');
    expect($job['status'])->toBe('success');
});

test('a weekly job successful 9 days ago is overdue, past its 8-day grace window', function () {
    saMakeUser(SystemUser::ROLE_SUPER_ADMIN);

    saWriteJobRun('break-glass:test', JobRunLog::STATUS_SUCCESS, now()->subDays(9));

    $response = $this->getJson('/api/system-analytics/scheduled-jobs-health')->assertOk();

    $job = collect($response->json('jobs'))->firstWhere('job_name', 'break-glass:test');
    expect($job['status'])->toBe('overdue');
});

test('needs_attention counts failed, stalled, overdue, and never_run jobs together', function () {
    saMakeUser(SystemUser::ROLE_SUPER_ADMIN);

    saWriteJobRun('notifications:shred-expired-requests', JobRunLog::STATUS_FAILED, now()->subMinutes(5), 'boom');
    saWriteJobRun('role-assignments:expire', JobRunLog::STATUS_RUNNING, now()->subMinutes(45)); // stalled
    saWriteJobRun('notifications:send-unclaimed-reminders', JobRunLog::STATUS_SUCCESS, now()->subHours(30)); // overdue
    // 'audit:verify' left with no row at all -> never_run
    saWriteJobRun('announcements:auto-disable-expired', JobRunLog::STATUS_SUCCESS, now()->subMinutes(5)); // healthy
    saWriteJobRun('provisioning:expire-stale', JobRunLog::STATUS_SUCCESS, now()->subMinutes(5)); // healthy
    saWriteJobRun('security-events:prune', JobRunLog::STATUS_SUCCESS, now()->subMinutes(5)); // healthy
    saWriteJobRun('job-run-logs:prune', JobRunLog::STATUS_SUCCESS, now()->subMinutes(5)); // healthy
    saWriteJobRun('break-glass:test', JobRunLog::STATUS_SUCCESS, now()->subMinutes(5)); // healthy

    $response = $this->getJson('/api/system-analytics/scheduled-jobs-health')->assertOk();

    // failed + stalled + overdue + never_run (audit:verify) = 4.
    expect($response->json('needs_attention'))->toBe(4);
});

test('only the latest row per job is considered, not an older healthy or unhealthy one', function () {
    saMakeUser(SystemUser::ROLE_SUPER_ADMIN);

    // An old failure followed by a recent success must report success —
    // scheduledJobsHealth() keys off MAX(job_run_id), not "has this job
    // ever failed".
    saWriteJobRun('role-assignments:expire', JobRunLog::STATUS_FAILED, now()->subHours(2), 'old failure');
    saWriteJobRun('role-assignments:expire', JobRunLog::STATUS_SUCCESS, now()->subMinutes(5));

    $response = $this->getJson('/api/system-analytics/scheduled-jobs-health')->assertOk();

    $job = collect($response->json('jobs'))->firstWhere('job_name', 'role-assignments:expire');
    expect($job['status'])->toBe('success');
    expect($job['error_message'])->toBeNull();
});

test('a plain admin cannot reach the scheduled-jobs-health endpoint', function () {
    saMakeUser(SystemUser::ROLE_ADMIN);

    $this->getJson('/api/system-analytics/scheduled-jobs-health')->assertForbidden();
});