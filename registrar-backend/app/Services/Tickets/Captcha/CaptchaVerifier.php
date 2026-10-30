<?php

namespace App\Services\Tickets\Captcha;

/**
 * Verifies the CAPTCHA response a public form sends along.
 *
 * An interface so the provider can change (or be faked in tests) without
 * touching the submission flow. Implementations FAIL CLOSED: any doubt means
 * false, never true.
 */
interface CaptchaVerifier
{
    public function verify(?string $token, ?string $ip): bool;
}
