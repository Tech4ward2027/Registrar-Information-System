<?php

namespace App\Services\Tickets\Captcha;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Server-side check against Cloudflare Turnstile's siteverify endpoint.
 * hCaptcha and reCAPTCHA take the same form fields (secret, response,
 * remoteip) and answer {"success": bool}, so pointing
 * config('tickets.public.captcha.verify_url') at them works unchanged.
 *
 * Fails closed: no token, no secret configured, a non-200 answer, an
 * unreachable provider or an unparseable reply all return false. A CAPTCHA
 * outage therefore pauses public submissions instead of opening the door; the
 * authenticated ticket flow is unaffected.
 */
class TurnstileCaptchaVerifier implements CaptchaVerifier
{
    public function verify(?string $token, ?string $ip): bool
    {
        if ($token === null || trim($token) === '') {
            return false;
        }

        $secret = config('tickets.public.captcha.secret');
        if (! is_string($secret) || $secret === '') {
            Log::error('tickets.captcha: enabled but TICKETS_CAPTCHA_SECRET is not set; refusing public submissions.');

            return false;
        }

        try {
            $response = Http::asForm()
                ->timeout(max(1, (int) config('tickets.public.captcha.timeout')))
                ->post((string) config('tickets.public.captcha.verify_url'), array_filter([
                    'secret'   => $secret,
                    'response' => $token,
                    'remoteip' => $ip,
                ]));

            return $response->ok() && $response->json('success') === true;
        } catch (Throwable $e) {
            Log::warning('tickets.captcha: verification request failed.', ['error' => $e->getMessage()]);

            return false;
        }
    }
}
