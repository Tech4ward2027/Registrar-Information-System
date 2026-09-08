<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\LogsJobRun;
use App\Models\AccessRequest;
use App\Models\AuditLog;
use App\Models\SystemUser;
use App\Services\AuditLogger;
use Illuminate\Console\Command;
use Illuminate\Http\Request;

/*
|--------------------------------------------------------------------------
| ExpireStaleProvisioning  (php artisan provisioning:expire-stale)
|--------------------------------------------------------------------------
| Two independent sweeps, both idempotent (safe to re-run / overlap-guarded
| by the scheduler entry in routes/console.php):
|
|   1. Any SystemUser still 'Pending Activation' past pending_expires_at
|      (14 days from creation) -> 'Expired'. NOT deleted — kept for audit
|      trail, per spec. A Super Admin can manually re-open one back to
|      'Pending Activation' via the normal edit endpoint if needed.
|
|   2. Any access_requests row still 'Requested' past expires_at
|      (7 days from creation) -> 'Expired'.
|
| Each expired row is audit-logged individually rather than as one bulk
| entry, so the audit trail can answer "when did THIS specific record
| expire" without cross-referencing a batch job's timestamp.
|--------------------------------------------------------------------------
*/
class ExpireStaleProvisioning extends Command
{
    use LogsJobRun;

    protected $signature   = 'provisioning:expire-stale';
    protected $description = 'Expire stale Pending Activation SystemUsers and unactioned access requests';

    /**
     * Job-Health Monitoring: see LogsJobRun's docblock. Logic below is
     * unchanged; only the outer try/catch and the two logging calls
     * around it are new. rows_affected is the SUM of both sweeps — the
     * per-sweep breakdown stays visible in the command's own $this->info
     * output/scheduler.log line, same as before.
     */
    public function handle(AuditLogger $auditLogger): int
    {
        $this->startJobRun($this->getName());

        try {
            // A synthetic Request so AuditLogger::log() (which expects one, to
            // extract browser/IP for a human-initiated action) has something
            // to call ->userAgent()/->ip() on. Both come back null/empty for a
            // console-originated action, which is the correct, honest value —
            // there is no browser or IP to record for a scheduled job.
            $request = Request::create('/console/provisioning-expire-stale', 'POST');

            $expiredUsers = $this->expireStaleUsers($auditLogger, $request);
            $expiredRequests = $this->expireStaleAccessRequests($auditLogger, $request);

            $this->info("provisioning:expire-stale — expired {$expiredUsers} pending user(s), {$expiredRequests} access request(s).");

            $this->finishJobRun(self::SUCCESS, $expiredUsers + $expiredRequests);
            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->failJobRun($e);
            throw $e;
        }
    }

    private function expireStaleUsers(AuditLogger $auditLogger, Request $request): int
    {
        $stale = SystemUser::where('status', 'Pending Activation')
            ->whereNotNull('pending_expires_at')
            ->where('pending_expires_at', '<', now())
            ->get(['user_id', 'email', 'role_id']);

        foreach ($stale as $user) {
            $user->update(['status' => 'Expired']);

            // System action, no human actor — pass the expired user itself
            // as the nominal actor (AuditLogger::log() requires a
            // SystemUser). Matches how UserProvisioningService attributes
            // the equivalent auto-activation event to the account it
            // happened to.
            $auditLogger->log($request, $user, AuditLog::ACTION_ADMIN_EXPIRED, [
                'target_user_id' => $user->user_id,
                'target_email'   => $user->email,
            ]);
        }

        return $stale->count();
    }

    private function expireStaleAccessRequests(AuditLogger $auditLogger, Request $request): int
    {
        $stale = AccessRequest::where('status', AccessRequest::STATUS_REQUESTED)
            ->where('expires_at', '<', now())
            ->with('requestedBy')
            ->get();

        foreach ($stale as $accessRequest) {
            $accessRequest->update(['status' => AccessRequest::STATUS_EXPIRED]);

            $actor = $accessRequest->requestedBy;
            if (!$actor) {
                // requested_by is a restrictOnDelete FK, so this should be
                // unreachable in practice — guarded defensively in case a
                // future migration relaxes that constraint.
                continue;
            }

            $auditLogger->log($request, $actor, AuditLog::ACTION_ACCESS_REQUEST_EXPIRED, [
                'target_email'      => $accessRequest->target_email,
                'access_request_id' => $accessRequest->id,
            ]);
        }

        return $stale->count();
    }
}