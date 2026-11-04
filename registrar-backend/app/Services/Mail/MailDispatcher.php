<?php

namespace App\Services\Mail;

use App\Jobs\SendLifecycleMailJob;
use App\Mail\RequestLifecycleMail;
use App\Models\AuditLog;
use App\Models\Notification;
use App\Models\NotificationDispatchLog;
use App\Models\NotificationType;
use App\Models\SystemUser;
use App\Models\DocumentRequest;
use App\Services\AuditLogger;
use App\Services\NotificationDispatchLogger;
use App\Services\RequestDocumentNames;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * The single gate every System Mailer email passes through.
 *
 * Two entry points:
 *   queueLifecycle()  the seven policy emails, driven by a saved notification
 *                     (called from NotificationService, runs as a queued job).
 *   sendApproved()    the five approved policy exceptions, which their own
 *                     services send directly.
 *
 * Both enforce the allow-list (MailPolicy) and the master switch, and both
 * write every attempt to notification_dispatch_logs (Policy Section 4).
 * Only the lifecycle path is subject to the quota cap; see config.
 */
class MailDispatcher
{
    public function __construct(
        private readonly NotificationDispatchLogger $log,
        private readonly MailQuotaService $quota,
        private readonly RepresentativeMailRouter $router,
        private readonly AuditLogger $audit,
    ) {}

    // ------------------------------------------------------------------
    // Lifecycle emails (the seven policy triggers)
    // ------------------------------------------------------------------

    /**
     * Decide whether a just-saved notification also goes out by email and, if
     * so, queue it. Never throws: email is a secondary channel and must not
     * turn a saved in-app notification into a failure.
     */
    public function queueLifecycle(Notification $notification, NotificationType $type): void
    {
        // getAttributes(): the type may come from a cache entry written
        // before the column existed (same defence as send_push).
        if (empty($type->getAttributes()['send_email'])) {
            return; // not an email type: nothing to log
        }

        $context = $this->context($notification, $type->trigger_event);

        if (! MailPolicy::isLifecycle($type->trigger_event)) {
            Log::warning('MailDispatcher: type flagged send_email is not on the policy allow-list', [
                'trigger_event' => $type->trigger_event,
            ]);
            $this->skip(NotificationDispatchLog::REASON_MAIL_NOT_APPROVED, $context);

            return;
        }

        if (! config('notification_mail.enabled')) {
            $this->skip(NotificationDispatchLog::REASON_MAIL_DISABLED, $context);

            return;
        }

        try {
            SendLifecycleMailJob::dispatch($notification->id);
        } catch (Throwable $e) {
            Log::warning('MailDispatcher: could not queue lifecycle email', [
                'notification_id' => $notification->id,
                'error'           => $e->getMessage(),
            ]);
            $this->log->record(NotificationDispatchLog::CHANNEL_MAIL, NotificationDispatchLog::STATUS_FAILED, $context + [
                'reason' => NotificationDispatchLog::REASON_QUEUE_ERROR,
                'error'  => $e->getMessage(),
            ]);
        }
    }

    /**
     * Runs inside SendLifecycleMailJob. Throws on a transport error so the
     * queue retries; every other outcome is a logged return.
     */
    public function deliver(string $notificationId): void
    {
        $notification = Notification::withTrashed()->with('type')->find($notificationId);

        if (! $notification || ! $notification->type) {
            $this->log->record(NotificationDispatchLog::CHANNEL_MAIL, NotificationDispatchLog::STATUS_SKIPPED, [
                'reason'          => NotificationDispatchLog::REASON_NOTIFICATION_GONE,
                'notification_id' => $notificationId,
            ]);

            return;
        }

        $trigger = $notification->type->trigger_event;
        $context = $this->context($notification, $trigger);

        // A retry after the transport accepted the message but before the
        // job finished must not send a second copy.
        $alreadySent = NotificationDispatchLog::where('channel', NotificationDispatchLog::CHANNEL_MAIL)
            ->where('status', NotificationDispatchLog::STATUS_SENT)
            ->where('notification_id', $notification->id)
            ->exists();
        if ($alreadySent) {
            return;
        }

        // Re-check at send time: the allow-list or master switch may have
        // changed since the job was queued.
        if (! MailPolicy::isLifecycle($trigger)) {
            $this->skip(NotificationDispatchLog::REASON_MAIL_NOT_APPROVED, $context);

            return;
        }
        if (! config('notification_mail.enabled')) {
            $this->skip(NotificationDispatchLog::REASON_MAIL_DISABLED, $context);

            return;
        }

        // Not filtered on status: a deceased requestor's account is usually
        // deactivated, and the §3.6 representative route must still work.
        $recipient = SystemUser::where('user_id', $notification->notifiable_id)->first();
        if (! $recipient) {
            $this->skip(NotificationDispatchLog::REASON_RECIPIENT_INACTIVE, $context);

            return;
        }

        // Policy §3.6: deceased or incapacitated requestor.
        $route = $this->router->route($recipient, $notification, $trigger);

        if ($route->kind === MailRoute::SUPPRESSED) {
            $this->skip(NotificationDispatchLog::REASON_REQUESTOR_FLAGGED, $context);

            return;
        }

        if ($route->kind === MailRoute::SENT_ON_FILE) {
            $this->recordSentOnFile($notification, $recipient, $context);

            return;
        }

        if ($route->kind === MailRoute::REPRESENTATIVE) {
            $address = filter_var($route->address, FILTER_VALIDATE_EMAIL) ? $route->address : null;
        } else {
            if ($recipient->status !== 'Activated') {
                $this->skip(NotificationDispatchLog::REASON_RECIPIENT_INACTIVE, $context);

                return;
            }

            $address = $this->resolveAddress($recipient, $notification);
        }

        if ($address === null) {
            $this->skip(NotificationDispatchLog::REASON_NO_EMAIL_ADDRESS, $context);

            return;
        }

        $duplicateKey = $this->duplicateKey($notification, $trigger);
        if (Cache::has($duplicateKey)) {
            $this->skip(NotificationDispatchLog::REASON_DUPLICATE, $context);

            return;
        }

        if (! $this->quota->lifecycleAllowed()) {
            $this->skip(NotificationDispatchLog::REASON_QUOTA_EXCEEDED, $context);
            $this->quota->evaluateAlerts();

            return;
        }

        try {
            Mail::to($address)->send($this->buildMailable($notification, $trigger, $route));
        } catch (Throwable $e) {
            $this->log->record(NotificationDispatchLog::CHANNEL_MAIL, NotificationDispatchLog::STATUS_FAILED, $context + [
                'reason'    => NotificationDispatchLog::REASON_EXCEPTION,
                'attempted' => 1,
                'error'     => $e->getMessage(),
            ]);

            throw $e; // let the queue retry with backoff
        }

        $this->log->record(NotificationDispatchLog::CHANNEL_MAIL, NotificationDispatchLog::STATUS_SENT, $context + [
            'reason'    => $route->kind === MailRoute::REPRESENTATIVE ? NotificationDispatchLog::REASON_VIA_REPRESENTATIVE : null,
            'attempted' => 1,
            'delivered' => 1,
        ]);

        Cache::put($duplicateKey, 1, now()->addMinutes((int) config('notification_mail.duplicate_window_minutes', 15)));
        $this->quota->evaluateAlerts();
    }

    // ------------------------------------------------------------------
    // Approved policy exceptions
    // ------------------------------------------------------------------

    /**
     * Send one of the five approved exception emails. These are verification
     * and access-link messages, so the quota counts them but never blocks
     * them. The Mailables are ShouldQueue; the 'sent' row therefore means
     * "handed to the queue", and a later transport failure shows up in
     * failed_jobs.
     *
     * @return bool false when the email was not attempted (not approved, or mail switched off)
     * @throws Throwable a hand-off failure is logged and rethrown, preserving each caller's existing error handling
     */
    public function sendApproved(string $trigger, string $to, Mailable $mailable, ?int $userId = null): bool
    {
        $context = ['trigger_event' => $trigger, 'user_id' => $userId];

        if (! MailPolicy::isApproved($trigger) || MailPolicy::isLifecycle($trigger)) {
            Log::error('MailDispatcher: refused to send an email that is not an approved exception', [
                'trigger_event' => $trigger,
            ]);
            $this->skip(NotificationDispatchLog::REASON_MAIL_NOT_APPROVED, $context);

            return false;
        }

        if (! config('notification_mail.enabled')) {
            $this->skip(NotificationDispatchLog::REASON_MAIL_DISABLED, $context);

            return false;
        }

        try {
            Mail::to($to)->send($mailable);
        } catch (Throwable $e) {
            $this->log->record(NotificationDispatchLog::CHANNEL_MAIL, NotificationDispatchLog::STATUS_FAILED, $context + [
                'reason'    => NotificationDispatchLog::REASON_EXCEPTION,
                'attempted' => 1,
                'error'     => $e->getMessage(),
            ]);

            throw $e;
        }

        $this->log->record(NotificationDispatchLog::CHANNEL_MAIL, NotificationDispatchLog::STATUS_SENT, $context + [
            'attempted' => 1,
            'delivered' => 1,
        ]);
        $this->quota->evaluateAlerts();

        return true;
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    /**
     * The requestor's own address, for the normal case. Representative and
     * suppression decisions (Policy Section 3.6) are made before this by
     * RepresentativeMailRouter.
     */
    private function resolveAddress(SystemUser $recipient, Notification $notification): ?string
    {
        $email = trim((string) $recipient->email);

        return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;
    }

    /**
     * Policy §3.6: the closure notice has no verified representative to go to.
     * The requestor's own address stays suppressed and the in-app notice is
     * the record. The audit entry is written once per notification even if the
     * job runs again.
     *
     * @param array<string,mixed> $context
     */
    private function recordSentOnFile(Notification $notification, SystemUser $recipient, array $context): void
    {
        $already = NotificationDispatchLog::where('channel', NotificationDispatchLog::CHANNEL_MAIL)
            ->where('reason', NotificationDispatchLog::REASON_SENT_ON_FILE)
            ->where('notification_id', $notification->id)
            ->exists();
        if ($already) {
            return;
        }

        // The actor is whoever closed the request; the requestor is never
        // recorded as the actor of their own closure.
        $closedBy = DocumentRequest::withArchived()->whereKey($notification->request_id)->value('closed_by');
        $actor    = ($closedBy ? SystemUser::find($closedBy) : null) ?? $recipient;

        // Audit first: if it throws, the queue retries and the skip row below
        // (which marks the notice as handled) is not yet written.
        $this->audit->logForSystem($actor, AuditLog::ACTION_CLOSURE_NOTICE_SENT_ON_FILE, [
            'request_id'     => $notification->request_id,
            'target_user_id' => $recipient->user_id,
            'reason'         => 'no_verified_representative',
        ]);

        $this->skip(NotificationDispatchLog::REASON_SENT_ON_FILE, $context);
    }

    private function buildMailable(Notification $notification, string $trigger, MailRoute $route): RequestLifecycleMail
    {
        $data = (array) ($notification->data ?? []);

        // Item-level deficiency notices name the item; everything else names
        // the request's documents.
        $itemName = isset($data['item_name']) ? trim((string) $data['item_name']) : '';
        if ($trigger === 'deficiency_notice_issued' && $itemName !== '') {
            [$names, $plural] = [$itemName, false];
        } else {
            [$names, $plural] = $this->documentNames($notification->request_id);
        }

        return new RequestLifecycleMail(
            trigger:      $trigger,
            documentName: $names,
            plural:       $plural,
            itemLabel:    isset($data['item_label']) ? (string) $data['item_label'] : null,
            reason:       $data['withdrawal_reason'] ?? $data['closure_reason'] ?? null,
            studentName:  $route->kind === MailRoute::REPRESENTATIVE ? $route->studentName : null,
        );
    }

    /** @return array{0:string,1:bool} comma-separated names, and whether there is more than one */
    private function documentNames(?int $requestId): array
    {
        return RequestDocumentNames::forRequest($requestId);
    }

    /**
     * Identical message, same person, same request, inside the window = one
     * email. Several code paths can announce the same status change; the
     * message hash keeps genuinely different notices (two deficiency items)
     * apart.
     */
    private function duplicateKey(Notification $notification, string $trigger): string
    {
        $message = (string) (((array) $notification->data)['message'] ?? '');

        return 'lifecycle_mail:' . sha1(implode('|', [
            $trigger,
            $notification->notifiable_id,
            $notification->request_id,
            $message,
        ]));
    }

    /** @return array<string,mixed> */
    private function context(Notification $notification, string $trigger): array
    {
        return [
            'trigger_event'   => $trigger,
            'notification_id' => $notification->id,
            'user_id'         => (int) $notification->notifiable_id,
            'request_id'      => $notification->request_id,
        ];
    }

    /** @param array<string,mixed> $context */
    private function skip(string $reason, array $context): void
    {
        $this->log->record(NotificationDispatchLog::CHANNEL_MAIL, NotificationDispatchLog::STATUS_SKIPPED, $context + [
            'reason' => $reason,
        ]);
    }
}