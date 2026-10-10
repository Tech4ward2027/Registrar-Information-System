<?php

namespace App\Support;

/**
 * Single choke point for telling the browser which feature flags are on.
 *
 * config/features.php is the source of truth for whether a feature is live
 * in this environment. The SPA only needs to know that for flags that
 * change what it renders (e.g. hide a card, hide a menu entry). Everything
 * the SPA is told goes through this class so that:
 *
 *   - exposure is an explicit ALLOW-LIST. A flag added to
 *     config/features.php is NOT visible to the browser until someone
 *     deliberately lists it in CLIENT_VISIBLE below (secure by default;
 *     no accidental disclosure of internal kill-switches).
 *   - the payload shape is stable: always a flat map of string => bool,
 *     never a raw config dump.
 *
 * IMPORTANT: hiding a UI element is a convenience, never a security
 * control. Every flagged capability is ALSO enforced server-side with the
 * 'feature:<flag>' route middleware (EnsureFeatureEnabled), which returns
 * 404 when the flag is off.
 */
final class FeatureFlags
{
    /**
     * Flags the SPA is allowed to read. Keep this list as small as the UI
     * genuinely needs.
     *
     * @var list<string>
     */
    public const CLIENT_VISIBLE = [
        'analytics_ai_legacy',
        'system_health',
        'ai_label_suggestions',
    ];

    /**
     * @return array<string,bool>  flag name => enabled
     */
    public static function forClient(): array
    {
        $flags = [];

        foreach (self::CLIENT_VISIBLE as $flag) {
            // (bool) cast + false default => an unknown or misspelled flag
            // fails closed rather than leaking null/garbage to the client.
            $flags[$flag] = (bool) config("features.{$flag}", false);
        }

        return $flags;
    }
}
