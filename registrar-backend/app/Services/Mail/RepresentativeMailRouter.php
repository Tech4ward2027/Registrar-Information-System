<?php

namespace App\Services\Mail;

use App\Enums\ClosureReasonEnum;
use App\Models\DocumentRequest;
use App\Models\Notification;
use App\Models\RequestorStatusFlag;
use App\Models\SystemUser;
use App\Services\Representative\RequestRepresentativeService;

/**
 * Notification Policy §3.6: who may receive a lifecycle email when the
 * requestor is deceased or permanently incapacitated.
 *
 *   normal requestor ............ their own address (unchanged behaviour)
 *   flagged, any other email .... suppressed: the account's own address is
 *                                 never emailed, and only the closure notice
 *                                 has a representative route
 *   flagged, closure notice ..... the verified representative only, or
 *                                 "sent-on-file" when there is none
 *
 * A closure with reason requestor_deceased / requestor_incapacitated is
 * treated as the §3.6 case even if nobody flagged the account, so a missed
 * flag can never send "your request could not be completed: requestor
 * deceased" to the deceased person's own inbox.
 *
 * Pending and revoked representatives count as "no representative".
 */
class RepresentativeMailRouter
{
    private const CLOSURE_TRIGGER = 'request_closed_unable_to_process';

    public function __construct(private RequestRepresentativeService $representatives) {}

    public function route(SystemUser $recipient, Notification $notification, string $trigger): MailRoute
    {
        $flagged = RequestorStatusFlag::active()->where('user_id', $recipient->user_id)->exists();
        $closure = $trigger === self::CLOSURE_TRIGGER;

        if (! $flagged && ! ($closure && $this->closedAsUnrecoverable($notification->request_id))) {
            return MailRoute::requestor();
        }

        if (! $closure) {
            return MailRoute::suppressed();
        }

        $representative = $this->representatives->verifiedFor($notification->request_id);
        if ($representative === null) {
            return MailRoute::sentOnFile();
        }

        return MailRoute::representative(
            (string) $representative->email,
            $this->requestorName($recipient),
        );
    }

    private function closedAsUnrecoverable(?int $requestId): bool
    {
        if ($requestId === null) {
            return false;
        }

        $reason = DocumentRequest::withArchived()->whereKey($requestId)->value('closure_reason');

        return in_array($reason, [
            ClosureReasonEnum::RequestorDeceased->value,
            ClosureReasonEnum::RequestorIncapacitated->value,
        ], true);
    }

    /** [student_name] in the §3.6 wording. */
    private function requestorName(SystemUser $user): string
    {
        $profile = match ((int) $user->role_id) {
            SystemUser::ROLE_STUDENT             => $user->studentProfile,
            SystemUser::ROLE_ALUMNI              => $user->alumniProfile,
            SystemUser::ROLE_UNDERGRAD_REQUESTOR => $user->undergradRequestorProfile,
            default                              => null,
        };

        $name = $profile ? trim("{$profile->first_name} {$profile->last_name}") : '';

        return $name !== '' ? $name : 'the requestor on file';
    }
}
