<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Accepts only HTTPS endpoints that belong to a known browser push service.
 *
 * Why: the backend makes an outbound POST to every registered endpoint. If a
 * client could register an arbitrary URL, a push subscription would become a
 * server-side request forgery primitive (internal hosts, cloud metadata, ...).
 */
class AllowedPushEndpoint implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            $fail('The :attribute must be a valid push endpoint.');
            return;
        }

        $parts = parse_url($value);

        $scheme = strtolower($parts['scheme'] ?? '');
        $host   = strtolower($parts['host'] ?? '');

        // No credentials, no custom ports — real push services use neither.
        if ($scheme !== 'https'
            || $host === ''
            || isset($parts['user'])
            || isset($parts['pass'])
            || (isset($parts['port']) && (int) $parts['port'] !== 443)
        ) {
            $fail('The :attribute must be a valid push endpoint.');
            return;
        }

        foreach ((array) config('webpush.allowed_endpoint_hosts', []) as $allowed) {
            $allowed = strtolower($allowed);
            if ($host === $allowed || str_ends_with($host, '.' . $allowed)) {
                return;
            }
        }

        $fail('The :attribute is not an accepted push service.');
    }
}
