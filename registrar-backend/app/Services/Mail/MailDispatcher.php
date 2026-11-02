<?php

namespace App\Services\Mail;

use App\Jobs\SendLifecycleMailJob;
use App\Mail\RequestLifecycleMail;
use App\Models\Notification;
use App\Models\NotificationDispatchLog;
use App\Models\NotificationType;
use App\Models\RequestCertificate;
use App\Models\RequestDocument;
use App\Models\SystemUser;
use App\Services\NotificationDispatchLogger;
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
    private const MAX_NAMES = 5;

    public function __construct(
        private readonly NotificationDispatchLogger $log,
        private readonly MailQuotaService $quota,
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

        $recipient = SystemUser::where('user_id', $notification->notifiable_id)
            ->where('status', 'Activated')
            ->first();
        if (! $recipient) {
            $this->skip(NotificationDispatchLog::REASON_RECIPIENT_INACTIVE, $context);

            return;
        }

        $address = $this->resolveAddress($recipient, $notification);
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
            Mail::to($address)->send($this->buildMailable($notification, $trigger));
        } catch (Throwable $e) {
            $this->log->record(NotificationDispatchLog::CHANNEL_MAIL, NotificationDispatchLog::STATUS_FAILED, $context + [
                'reason'    => NotificationDispatchLog::REASON_EXCEPTION,
                'attempted' => 1,
                'error'     => $e->getMessage(),
            ]);

            throw $e; // let the queue retry with backoff
        }

        $this->log->record(NotificationDispatchLog::CHANNEL_MAIL, NotificationDispatchLog::STATUS_SENT, $context + [
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
     * Where a lifecycle email goes. Today: the requestor's own address.
     * Phase 6 (deceased or incapacitated requestor, Policy Section 3.6)
     * replaces this with representative routing and suppresses the personal
     * address; it is a separate method so that change touches one place.
     */
    private function resolveAddress(SystemUser $recipient, Notification $notification): ?string
    {
        $email = trim((string) $recipient->email);

        return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;
    }

    private function buildMailable(Notification $notification, string $trigger): RequestLifecycleMail
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
        );
    }

    /** @return array{0:string,1:bool} comma-separated names, and whether there is more than one */
    private function documentNames(?int $requestId): array
    {
        if ($requestId === null) {
            return ['your request', false];
        }

        $names = RequestDocument::where('request_id', $requestId)->with('documentType')->get()
            ->map(fn ($d) => $d->documentType?->document_name)
            ->merge(
                RequestCertificate::where('request_id', $requestId)->with('certificationType')->get()
                    ->map(fn ($c) => $c->certificationType?->certificate_name)
            )
            ->filter()
            ->unique()
            ->values();

        if ($names->isEmpty()) {
            return ['your request', false];
        }

        $total = $names->count();
        $shown = $names->take(self::MAX_NAMES)->implode(', ');
        if ($total > self::MAX_NAMES) {
            $shown .= ' and ' . ($total - self::MAX_NAMES) . ' more';
        }

        return [$shown, $total > 1];
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
