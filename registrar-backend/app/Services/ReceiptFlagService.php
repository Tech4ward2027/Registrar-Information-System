<?php

namespace App\Services;

use App\Jobs\SendBulkNotificationJob;
use App\Models\AuditLog;
use App\Models\DocumentRequest;
use App\Models\SystemUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Policy §3.4 "Mismatched Receipt Flagged": a staff member who finds an
 * Official Receipt mismatched or altered flags the request. The flag is
 * written to the append-only audit trail and Super Admin (which performs the
 * Technical/IT duties, see config notification_matrix.technical_it_role_ids)
 * is alerted. It changes no request status: what to do about the receipt is
 * a human decision.
 *
 * Idempotent per request for a day, so a double click or a retry does not
 * page Super Admin twice; the audit entry is still written every time.
 */
class ReceiptFlagService
{
    private const DEDUPE_HOURS = 24;

    public function __construct(private AuditLogger $auditLogger) {}

    /** @return bool true when Super Admin was notified, false when deduplicated */
    public function flag(DocumentRequest $documentRequest, SystemUser $actor, string $reason, Request $request): bool
    {
        $this->auditLogger->log($request, $actor, AuditLog::ACTION_RECEIPT_FLAGGED, [
            'request_id' => $documentRequest->request_id,
            'or_number'  => $documentRequest->or_number,
            'reason'     => $reason,
        ]);

        $first = Cache::add("receipt-flag:{$documentRequest->request_id}", true, now()->addHours(self::DEDUPE_HOURS));

        if (! $first) {
            return false;
        }

        SendBulkNotificationJob::dispatch(
            triggerEvent: 'mismatched_receipt_flagged',
            data:         ['request_id' => $documentRequest->request_id],
            onlyRoleIds:  (array) config('notification_matrix.technical_it_role_ids', [SystemUser::ROLE_SUPER_ADMIN]),
            requestId:    $documentRequest->request_id,
        );

        return true;
    }
}
