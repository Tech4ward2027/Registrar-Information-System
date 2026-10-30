<?php

namespace App\Services\Tickets\Captcha;

/** Used only when config('tickets.public.captcha_enabled') is false (local development, tests). */
class NullCaptchaVerifier implements CaptchaVerifier
{
    public function verify(?string $token, ?string $ip): bool
    {
        return true;
    }
}
