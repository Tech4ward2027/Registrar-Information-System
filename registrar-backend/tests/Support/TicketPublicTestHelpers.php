<?php

/*
 * Helpers for the public (no account) ticket tests. tkPub prefix; see
 * TicketApiTestHelpers.php for why helpers are require_once'd and prefixed.
 */

use App\Models\Ticket;
use App\Services\Tickets\Captcha\CaptchaVerifier;
use App\Services\Tickets\PublicSubmission\PublicFormToken;
use App\Services\Tickets\PublicSubmission\TicketAccessTokenService;
use Illuminate\Support\Facades\Mail;

require_once __DIR__ . '/TicketApiTestHelpers.php';

/** Flag on, mail faked, and every anti-bot layer that needs a real browser switched off. Tests re-enable what they exercise. */
function tkPubSetup(): void
{
    tkApiOn();
    Mail::fake();
    config([
        'tickets.public.captcha_enabled'  => false,
        'tickets.public.min_fill_seconds' => 0,
        // Raised so a test is not tripped by an unrelated ceiling; tests lower the one they target.
        'tickets.rate_limits.public.start_per_ip_per_minute'      => 1000,
        'tickets.rate_limits.public.verification_emails_per_ip_per_hour' => 1000,
        'tickets.rate_limits.public.tickets_per_ip_per_day'       => 1000,
    ]);
}

/** Make the CAPTCHA check answer true or false. */
function tkPubCaptcha(bool $passes): void
{
    app()->instance(CaptchaVerifier::class, new class($passes) implements CaptchaVerifier {
        public function __construct(private bool $passes) {}

        public function verify(?string $token, ?string $ip): bool
        {
            return $this->passes;
        }
    });

    // The router keeps the controller instance it built on the first request,
    // and that controller holds the verifier that was current then. Without
    // this, swapping the verifier after a request has been made has no effect.
    foreach (app('router')->getRoutes() as $route) {
        $route->flushController();
    }
}

function tkPubPayload(array $overrides = []): array
{
    return array_merge([
        'name'          => 'Juan Dela Cruz',
        'email'         => 'juan@example.com',
        'category_code' => 'CAT-05',
        'subject'       => 'Portal shows an error',
        'description'   => 'The portal shows an error page when I open my records.',
        'form_token'    => app(PublicFormToken::class)->issue(),
        'captcha_token' => 'captcha-ok',
    ], $overrides);
}

/** The plain token inside the last queued mail of this class (the part after #token=). */
function tkPubMailedToken(string $mailable): string
{
    $mail = Mail::queued($mailable)->last();
    $url  = $mail->confirmUrl ?? $mail->trackUrl;

    return substr($url, strpos($url, '#token=') + 7);
}

/** start() then confirm(): a public ticket via the real flow. Returns [response, plainTrackingToken]. */
function tkPubFileTicket(array $overrides = []): array
{
    test()->postJson('/api/v1/public/tickets/start', tkPubPayload($overrides))->assertStatus(202);
    $token = tkPubMailedToken(\App\Mail\TicketPublicVerificationMail::class);

    $res = test()->postJson('/api/v1/public/tickets/confirm-email', ['token' => $token])->assertCreated();

    return [$res, $res->json('tracking_token')];
}

/** A public ticket created directly (no email round trip) with a magic link. Returns [Ticket, plainToken]. */
function tkPubTicket(string $email = 'juan@example.com', string $category = 'CAT-05'): array
{
    $ticket = tkService()->createForPublic('Juan Dela Cruz', $email, tkCategory($category), 'Portal bug', 'The portal shows an error page.', '203.0.113.5');

    return [$ticket, app(TicketAccessTokenService::class)->issue($ticket)];
}

function tkPubTrack(string $token, string $suffix = ''): array
{
    return ['/api/v1/public/tickets/track' . $suffix, ['X-Ticket-Token' => $token]];
}