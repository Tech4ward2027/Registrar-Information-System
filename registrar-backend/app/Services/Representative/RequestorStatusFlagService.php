<?php

namespace App\Services\Representative;

use App\Models\AuditLog;
use App\Models\RequestorStatusFlag;
use App\Models\RequestRepresentative;
use App\Models\SystemUser;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Marks a requestor account deceased or permanently incapacitated
 * (Notification Policy §3.6).
 *
 * Flagging takes effect immediately and is open to staff: it only ever
 * REDUCES what is sent (the System Mailer stops emailing the account's own
 * address), so acting early is the safe direction. Lifting a flag is the
 * risky direction, so it is Super Admin only and also revokes every
 * representative attached to the requestor's requests, since their authority
 * came from the flag.
 */
class RequestorStatusFlagService
{
    private const REQUESTOR_ROLES = [
        SystemUser::ROLE_STUDENT,
        SystemUser::ROLE_ALUMNI,
        SystemUser::ROLE_UNDERGRAD_REQUESTOR,
    ];

    public function __construct(
        private AuditLogger $auditLogger,
        private RequestRepresentativeService $representatives,
    ) {}

    public function flag(SystemUser $requestor, string $status, ?string $note, SystemUser $actor, Request $request): RequestorStatusFlag
    {
        if (! in_array((int) $requestor->role_id, self::REQUESTOR_ROLES, true)) {
            abort(422, 'Only a student, alumni or undergrad requestor account can be flagged.');
        }

        $flag = DB::transaction(function () use ($requestor, $status, $note, $actor) {
            // One row per account; a re-flag after a lift reuses it (history
            // is in audit_logs).
            return RequestorStatusFlag::updateOrCreate(
                ['user_id' => $requestor->user_id],
                [
                    'status'      => $status,
                    'note'        => $note,
                    'flagged_by'  => $actor->user_id,
                    'lifted_at'   => null,
                    'lifted_by'   => null,
                    'lift_reason' => null,
                ],
            );
        });

        $this->auditLogger->log($request, $actor, AuditLog::ACTION_REQUESTOR_FLAGGED, [
            'status'         => $status,
            'target_user_id' => $requestor->user_id,
        ]);

        return $flag;
    }

    public function lift(RequestorStatusFlag $flag, SystemUser $actor, string $reason, Request $request): RequestorStatusFlag
    {
        $revoked = [];

        DB::transaction(function () use (&$flag, $actor, $reason, &$revoked) {
            $flag = RequestorStatusFlag::whereKey($flag->id)->lockForUpdate()->firstOrFail();

            if (! $flag->isActive()) {
                abort(409, 'This flag is already lifted.');
            }

            $flag->update([
                'lifted_at'   => now(),
                'lifted_by'   => $actor->user_id,
                'lift_reason' => $reason,
            ]);

            $revoked = RequestRepresentative::query()
                ->whereIn('request_id', fn ($q) => $q->select('request_id')->from('document_request')->where('user_id', $flag->user_id))
                ->where('status', '!=', RequestRepresentative::STATUS_REVOKED)
                ->get()
                ->all();
        });

        $this->auditLogger->log($request, $actor, AuditLog::ACTION_REQUESTOR_FLAG_LIFTED, [
            'target_user_id' => $flag->user_id,
        ]);

        foreach ($revoked as $representative) {
            $this->representatives->revoke($representative, $actor, 'Requestor flag lifted.', $request);
        }

        return $flag;
    }
}
