<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\LogsJobRun;
use App\Models\AuditLog;
use App\Models\RoleAssignment;
use App\Services\AuditLogger;
use Illuminate\Console\Command;
use Illuminate\Http\Request;

/*
|--------------------------------------------------------------------------
| ExpireRoleAssignments  (php artisan role-assignments:expire)
|--------------------------------------------------------------------------
| Sweeps any role_assignments row still 'Active' past its expires_at ->
| 'Expired', and force-revokes that account's Sanctum tokens the same
| way an explicit revoke() does (see RoleAssignmentService::revoke()) —
| an unrenewed grant should lose its session immediately at expiry, not
| linger until the token happens to lapse on its own.
|
| This is the safety net for offboarding scenarios RIS has no live
| signal for — most notably graduation: student_academic_record has no
| graduation_date column, and OGOS's DTO exposes no enrollment-status
| field (confirmed against the actual client/DTO), so RIS cannot detect
| "this student-staff member graduated" as an event. Time-boxing the
| grant at creation (RoleAssignmentService::grant()) and sweeping it
| here means an unrenewed assignment lapses on its own on schedule,
| rather than silently persisting past the person's actual tenure.
|
| Deliberate design note: this does NOT touch the account's OTHER role
| assignments. A student-staff member's expired Admin grant does not
| affect their Student assignment — offboarding one role should never
| collaterally offboard another the person still legitimately holds.
|
| Each expiry is audit-logged individually (not one bulk entry), same
| reasoning as ExpireStaleProvisioning: the audit trail should be able
| to answer "when did THIS specific assignment expire" without
| cross-referencing a batch job's timestamp.
|--------------------------------------------------------------------------
*/
class ExpireRoleAssignments extends Command
{
    use LogsJobRun;

    protected $signature   = 'role-assignments:expire';
    protected $description = 'Expire role assignments past their expires_at and revoke the affected sessions';

    /**
     * Job-Health Monitoring: see LogsJobRun's docblock. Logic below is
     * unchanged; only the outer try/catch and the two logging calls
     * around it are new.
     */
    public function handle(AuditLogger $auditLogger): int
    {
        $this->startJobRun($this->getName());

        try {
            // Synthetic request for the same reason ExpireStaleProvisioning
            // uses one — AuditLogger::log() expects something to read
            // browser/IP from; both come back empty for a console-originated
            // action, which is the honest value for a scheduled job.
            $request = Request::create('/console/role-assignments-expire', 'POST');

            $expired = $this->expireDueAssignments($auditLogger, $request);

            $this->info("role-assignments:expire — expired {$expired} role assignment(s).");

            $this->finishJobRun(self::SUCCESS, $expired);
            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->failJobRun($e);
            throw $e;
        }
    }

    private function expireDueAssignments(AuditLogger $auditLogger, Request $request): int
    {
        $due = RoleAssignment::dueToExpire()
            ->with('user')
            ->get();

        foreach ($due as $assignment) {
            $assignment->update(['status' => RoleAssignment::STATUS_EXPIRED]);

            $targetUser = $assignment->user;

            if (!$targetUser) {
                // user_id is a cascadeOnDelete FK, so this should be
                // unreachable in practice — guarded defensively (same
                // pattern as ExpireStaleProvisioning's requested_by
                // check) since AuditLogger::log() requires a non-null
                // SystemUser actor and there's nothing meaningful to
                // attribute this row's expiry to if the user is gone.
                continue;
            }

            $targetUser->tokens()->delete();

            // System action, no human actor — attribute to the affected
            // user themselves, same convention ExpireStaleProvisioning
            // uses for auto-expired SystemUser rows.
            $auditLogger->log($request, $targetUser, AuditLog::ACTION_ROLE_EXPIRED, [
                'target_user_id'     => $targetUser->user_id,
                'target_email'       => $targetUser->email,
                'role_assignment_id' => $assignment->id,
                'role_id'            => $assignment->role_id,
            ]);
        }

        return $due->count();
    }
}