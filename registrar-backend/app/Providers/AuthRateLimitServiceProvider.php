<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

/**
 * Named rate limiters for the two credential endpoints (BACKEND ACTION ITEM 9.10).
 *
 *   throttle:login        -> POST /api/v1/login
 *   throttle:local-login  -> POST /api/v1/auth/local-login
 *
 * Replaces the flat `throttle:60,1` (per IP only) with a per-IP bucket PLUS
 * per-account buckets. See config/login_throttle.php for the rationale and the
 * tunable values.
 *
 * Privacy / hygiene: the account bucket is keyed on a SHA-256 of the normalised
 * e-mail, so the address itself never appears in cache keys, Redis dumps or
 * monitoring output.
 */
class AuthRateLimitServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->defineLimiter('login', 'login');
        $this->defineLimiter('local-login', 'local_login');
    }

    /**
     * @param string $name      Limiter name used in routes (`throttle:<name>`).
     * @param string $configKey Key under config/login_throttle.php.
     */
    private function defineLimiter(string $name, string $configKey): void
    {
        RateLimiter::for($name, function (Request $request) use ($name, $configKey) {
            $cfg = (array) config("login_throttle.{$configKey}", []);

            $perIp      = max(1, (int) ($cfg['per_ip_per_minute'] ?? 60));
            $perAcctMin = max(1, (int) ($cfg['per_account_per_minute'] ?? 10));
            $perAcctHr  = max(1, (int) ($cfg['per_account_per_hour'] ?? 50));

            // $request->ip() can be null behind a misconfigured proxy; a named
            // placeholder keeps such callers in one explicit bucket instead of
            // producing a malformed cache key.
            $ip = $request->ip() ?? 'unknown';

            $limits = [
                Limit::perMinute($perIp)->by("{$name}:ip:{$ip}"),
            ];

            // Only add account buckets when a usable e-mail was submitted.
            // Malformed requests (no email, array, etc.) are rejected by
            // LoginRequest with a 422 and remain covered by the IP bucket.
            $account = $this->accountKey($request);

            if ($account !== null) {
                $limits[] = Limit::perMinute($perAcctMin)->by("{$name}:acct-min:{$account}");
                $limits[] = Limit::perHour($perAcctHr)->by("{$name}:acct-hr:{$account}");
            }

            return $limits;
        });
    }

    /**
     * Stable, non-reversible identifier for the targeted account, or null.
     *
     * Normalised (trim + lowercase) so "Admin@X.com" and "admin@x.com " share a
     * bucket, and length-capped so an oversized value cannot be used to bloat
     * the cache. Email is case-insensitive in practice for this system's
     * accounts; treating it that way here prevents case-variation bypass.
     */
    private function accountKey(Request $request): ?string
    {
        $email = $request->input('email');

        if (! is_string($email)) {
            return null;
        }

        $email = mb_strtolower(trim($email));

        if ($email === '' || strlen($email) > 254) {
            return null;
        }

        return hash('sha256', $email);
    }
}
