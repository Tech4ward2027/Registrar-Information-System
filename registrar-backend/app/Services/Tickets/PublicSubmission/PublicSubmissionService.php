<?php

namespace App\Services\Tickets\PublicSubmission;

use App\Exceptions\Tickets\TicketRateLimitedException;
use App\Mail\TicketPublicAccessLinkMail;
use App\Mail\TicketPublicVerificationMail;
use App\Models\Ticket;
use App\Models\TicketCategory;
use App\Models\TicketPublicSubmission;
use App\Services\Tickets\Captcha\CaptchaVerifier;
use App\Services\Tickets\TicketService;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Support Ticketing — Phase 4: the public (no account) submission flow.
 *
 *   start    the person fills the form -> anti-abuse checks -> a pending
 *            submission is stored and a confirmation email is sent. NO TICKET YET.
 *   confirm  they click the emailed link -> the ticket is created and a magic
 *            link for following it is issued.
 *   resend   "I lost my link" -> a fresh magic link is emailed, with an answer
 *            that is identical whether or not anything matched.
 *
 * Layers against abuse, in the order they run on `start` (cheap and local
 * first, the external CAPTCHA call and the database after):
 *   1. honeypot field filled      -> pretend it worked, do nothing
 *   2. minimum fill time          -> refuse (the form token proves elapsed time)
 *   3. CAPTCHA                    -> refuse
 *   4. blocked email / IP         -> pretend it worked, do nothing
 *   5. 3 verification emails per address per hour -> 429
 *   6. per-day and open-ticket ceilings -> 429 / 409
 * plus, in front of all of it, the per-IP route limiters. The per-address hourly
 * counter deliberately sits AFTER the anti-bot checks: counted earlier, anyone
 * could lock a victim out of filing by posting junk with the victim's address.
 *
 * "Pretend it worked" (1 and 4) returns the same answer a real success does, so
 * a bot or a blocked person gets no signal to adapt to.
 */
class PublicSubmissionService
{
    /** Names the lock/limiter bucket for an address without storing the address in a cache key. */
    private function emailKey(string $email): string
    {
        return sha1(mb_strtolower(trim($email)));
    }

    public function __construct(
        private readonly TicketService $tickets,
        private readonly TicketAccessTokenService $accessTokens,
        private readonly TicketBlockList $blocks,
        private readonly PublicTicketLimits $limits,
        private readonly PublicFormToken $formToken,
        private readonly CaptchaVerifier $captcha,
    ) {}

    /**
     * @param  array{name:string,email:string,category_code:string,subject:string,description:string,form_token:?string,captcha_token:?string,honeypot:bool}  $input
     *
     * @throws ValidationException           form token or CAPTCHA refused
     * @throws TicketRateLimitedException    a ceiling was reached
     * @throws \App\Exceptions\Tickets\PublicOpenTicketLimitException
     */
    public function start(array $input, ?string $ip): void
    {
        // 1. Honeypot: a field no human sees. Filled means a script did it.
        if ($input['honeypot']) {
            return;
        }

        // 2. Minimum fill time (and a token that is genuine and not stale).
        $age = $this->formToken->ageSeconds($input['form_token'] ?? null);
        $max = (int) config('tickets.public.form_token_max_age_minutes') * 60;

        if ($age === null || $age > $max) {
            throw ValidationException::withMessages([
                'form_token' => 'This form has expired. Please reload the page and try again.',
            ]);
        }
        if ($age < (int) config('tickets.public.min_fill_seconds')) {
            throw ValidationException::withMessages([
                'form_token' => 'That was very quick. Please check your message and submit again.',
            ]);
        }

        // 3. CAPTCHA.
        if (! $this->captcha->verify($input['captcha_token'] ?? null, $ip)) {
            throw ValidationException::withMessages([
                'captcha_token' => 'We could not verify that you are human. Please try again.',
            ]);
        }

        $email = mb_strtolower(trim($input['email']));

        // 4. Blocked: same answer as success.
        if ($this->blocks->isBlocked($email, $ip)) {
            return;
        }

        // 5. Verification emails per address per hour.
        $this->hitVerificationEmailLimit($email);

        // 6. Daily / open-ticket ceilings, so a doomed request gets an answer rather than an email.
        $this->limits->assertWithinLimits($email, $ip);

        $category = TicketCategory::query()->active()->where('code', $input['category_code'])->firstOrFail();

        $plain = $this->newToken();
        $ttl   = (int) config('tickets.public.verification_ttl_minutes');

        TicketPublicSubmission::query()->create([
            'token_hash'  => hash('sha256', $plain),
            'name'        => trim($input['name']),
            'email'       => $email,
            'category_id' => $category->getKey(),
            'subject'     => $input['subject'],
            'description' => $input['description'],
            'ip'          => $ip,
            'expires_at'  => now()->addMinutes($ttl),
        ]);

        Mail::to($email)->send(new TicketPublicVerificationMail(
            name:       trim($input['name']),
            confirmUrl: $this->confirmUrl($plain),
            ttlMinutes: $ttl,
        ));
    }

    /**
     * Confirm the email and create the ticket.
     *
     * @return array{0: Ticket, 1: string} the ticket and the PLAIN magic-link token
     *
     * @throws ValidationException           the link is invalid, expired or already used
     * @throws TicketRateLimitedException
     * @throws \App\Exceptions\Tickets\PublicOpenTicketLimitException
     */
    public function confirm(string $token, ?string $ip): array
    {
        $invalid = fn (string $message = 'This confirmation link is invalid or has expired. Please submit the form again.')
            => ValidationException::withMessages(['token' => $message]);

        if (! preg_match('/^[A-Za-z0-9_-]{40,200}$/', $token)) {
            throw $invalid();
        }

        $submission = TicketPublicSubmission::query()->where('token_hash', hash('sha256', $token))->first();

        // Unknown, expired and tampered are one answer. Only "already used" differs, and it
        // reveals nothing: the person holding the link is the one who was emailed it.
        if ($submission === null || $submission->expires_at->isPast()) {
            throw $invalid();
        }
        if ($submission->consumed_at !== null) {
            throw $invalid('This request was already confirmed. Check your email for your ticket link.');
        }

        if ($this->blocks->isBlocked($submission->email, $submission->ip)) {
            throw $invalid();
        }

        $category = TicketCategory::query()->active()->find($submission->category_id);
        if ($category === null) {
            throw $invalid('That category is no longer available. Please submit the form again.');
        }

        // One confirmation per address at a time, so two quick clicks cannot both pass the
        // ceilings and both create a ticket.
        try {
            [$ticket, $plain] = Cache::lock('tickets-public-create:' . $this->emailKey($submission->email), 15)
                ->block(5, fn () => $this->createFromSubmission($submission, $category, $ip, $invalid));
        } catch (LockTimeoutException) {
            throw new TicketRateLimitedException(5, 'confirm_lock');
        }

        $this->sendAccessLink($submission->email, $ticket, $plain);

        return [$ticket, $plain];
    }

    /**
     * "Resend my link". Always returns normally and sends nothing unless the
     * address and ticket number match a ticket whose link is still valid, so the
     * caller can show one fixed message either way.
     */
    public function resendLink(string $email, string $ticketNumber): void
    {
        $email = mb_strtolower(trim($email));

        $ticket = Ticket::query()
            ->where('ticket_number', strtoupper(trim($ticketNumber)))
            ->where('requester_email', $email)
            ->whereNull('requester_user_id')
            ->first();

        if ($ticket === null || ! $this->accessTokens->linkStillValid($ticket) || $this->blocks->isBlocked($email, null)) {
            return;
        }

        $this->sendAccessLink($email, $ticket, $this->accessTokens->issue($ticket));
    }

    /** @return array{0: Ticket, 1: string} */
    private function createFromSubmission(TicketPublicSubmission $submission, TicketCategory $category, ?string $ip, \Closure $invalid): array
    {
        // The authoritative check: counted again now, under the per-address lock.
        $this->limits->assertWithinLimits($submission->email, $ip ?? $submission->ip);

        return DB::transaction(function () use ($submission, $category, $ip, $invalid) {
            // Atomic "claim": only one request can flip consumed_at from null, so a double
            // click or a replay cannot create a second ticket.
            $claimed = TicketPublicSubmission::query()
                ->whereKey($submission->getKey())
                ->whereNull('consumed_at')
                ->where('expires_at', '>', now())
                ->update(['consumed_at' => now()]);

            if ($claimed !== 1) {
                throw $invalid('This request was already confirmed. Check your email for your ticket link.');
            }

            $ticket = $this->tickets->createForPublic(
                $submission->name ?? '',
                $submission->email,
                $category,
                (string) $submission->subject,
                (string) $submission->description,
                $submission->ip ?? $ip,
            );

            // The text now lives on the ticket; do not keep a second copy.
            $submission->forceFill([
                'ticket_id'   => $ticket->getKey(),
                'name'        => null,
                'subject'     => null,
                'description' => null,
            ])->save();

            return [$ticket, $this->accessTokens->issue($ticket)];
        });
    }

    private function sendAccessLink(string $email, Ticket $ticket, string $plain): void
    {
        try {
            Mail::to($email)->send(new TicketPublicAccessLinkMail(
                ticketNumber: $ticket->ticket_number,
                trackUrl:     $this->accessTokens->trackUrl($plain),
                graceDays:    (int) config('tickets.public.token_grace_days'),
            ));
        } catch (Throwable $e) {
            // The ticket exists and (on confirm) the response carries the link, so a mail
            // failure must not turn a success into an error. It is logged for follow-up.
            Log::error('tickets.public: access-link email could not be queued.', [
                'ticket' => $ticket->ticket_number,
                'error'  => $e->getMessage(),
            ]);
        }
    }

    private function hitVerificationEmailLimit(string $email): void
    {
        $key = 'tickets-public-verify:' . $this->emailKey($email);
        $max = (int) config('tickets.rate_limits.public.verification_emails_per_email_per_hour');

        if ($max > 0 && RateLimiter::tooManyAttempts($key, $max)) {
            throw new TicketRateLimitedException(max(1, RateLimiter::availableIn($key)), 'verify_email_hour');
        }

        RateLimiter::hit($key, 3600);
    }

    private function newToken(): string
    {
        $bytes = max(32, (int) config('tickets.public.token_bytes'));

        return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
    }

    private function confirmUrl(string $plain): string
    {
        return rtrim((string) config('app.frontend_url'), '/')
            . '/' . ltrim((string) config('tickets.public.links.confirm_path'), '/')
            . '#token=' . $plain;
    }
}
