<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Forwards a legacy undergrad e-mail verification link from the API host to
 * the frontend.
 *
 * WHY THIS EXISTS
 * UndergradRequestorRegistrationService::buildVerificationUrl() builds the
 * e-mailed link from config('app.frontend_url'), which falls back to APP_URL
 * when FRONTEND_URL is unset. In a split-origin deployment (SPA and API on
 * different hosts) that fallback produced links on the API host, e.g.
 *
 *   https://<api-host>/undergrad-requestor/verify-email?email=...&token=...
 *
 * but that page only exists in the SPA, so the API host answered 404 and
 * the registrant could never verify. Fixing FRONTEND_URL repairs every NEW
 * e-mail; this endpoint rescues the e-mails that were already sent.
 *
 * It is safe to keep permanently: it is stateless, has a fixed destination
 * path, and does nothing when the API and frontend share a host.
 *
 * SECURITY
 *  - No open redirect: the destination host comes only from server config
 *    (FRONTEND_URL) and the path is hard-coded. Nothing from the request
 *    chooses where the browser goes.
 *  - Only the `email` and `token` query keys are forwarded, and only as
 *    strings; any other parameter is dropped.
 *  - The token is a credential in the URL, so the response is never cached
 *    and sends `Referrer-Policy: no-referrer` so it cannot leak through the
 *    Referer header. The token is not logged here.
 *  - Redirect-loop guard: if FRONTEND_URL resolves to the host that received
 *    the request (single-origin dev/staging, or a misconfigured value), the
 *    route answers 404 instead of redirecting to itself.
 */
final class UndergradVerificationLinkRedirectController extends Controller
{
    private const FRONTEND_PATH = '/undergrad-requestor/verify-email';

    /** Query keys the SPA verification page reads — nothing else is forwarded. */
    private const FORWARDED_QUERY_KEYS = ['email', 'token'];

    public function __invoke(Request $request): RedirectResponse
    {
        $frontend = rtrim((string) config('app.frontend_url'), '/');
        $parts    = parse_url($frontend);

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host   = (string) ($parts['host'] ?? '');

        if (! in_array($scheme, ['http', 'https'], true) || $host === '') {
            abort(404);
        }

        // Same host => nothing to forward to (the SPA owns this path there).
        if (strcasecmp($host, $request->getHost()) === 0) {
            abort(404);
        }

        $query = array_filter(
            $request->only(self::FORWARDED_QUERY_KEYS),
            static fn ($value): bool => is_string($value) && $value !== '',
        );

        $url = $frontend . self::FRONTEND_PATH
            . ($query === [] ? '' : '?' . http_build_query($query));

        // 302, not 301: a permanent redirect would be cached by browsers and
        // outlive a later change to FRONTEND_URL.
        return redirect()->away($url, 302, [
            'Cache-Control'   => 'no-store, private',
            'Referrer-Policy' => 'no-referrer',
        ]);
    }
}
