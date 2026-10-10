<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * FailureReasonCode
 * =================
 * Descriptive catalog for the diagnosis codes CashierFailureDiagnosisService
 * attaches to a failed cashier verification (Cashier Reconciliation /
 * System Health, Phase 2b).
 *
 * WHAT IS AND IS NOT DATA-DRIVEN. The rules that decide WHEN a code
 * applies are deterministic code (CashierFailureDiagnosisService) and
 * change only with a deploy. This table owns everything ABOUT a code —
 * its label, category, severity, and whether it is currently emitted —
 * so those can be tuned, or a noisy code switched off (is_active = false),
 * without a deploy. A code the service emits that has no row here is kept
 * (fail open: a missing catalog row must never hide a diagnosis).
 *
 * WORDING RULE. Every description reads as a "likely contributing
 * factor", never a fault verdict: the Cashier API does not return the
 * name it has on file, so the cashier-side cause cannot be determined
 * from RIS.
 */
class FailureReasonCode extends Model
{
    protected $primaryKey = 'failure_reason_code_id';

    protected $fillable = ['code', 'category', 'description', 'severity', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    public const CATEGORY_SOURCE = 'source'; // an upstream system could not supply data
    public const CATEGORY_DATA   = 'data';   // the data we hold disagrees / was exhausted
    public const CATEGORY_FORMAT = 'format'; // name shape that makes exact matching fragile

    public const SEVERITY_INFO    = 'info';
    public const SEVERITY_WARNING = 'warning';

    private const INACTIVE_CACHE_KEY = 'failure_reason_codes:inactive';
    private const INACTIVE_CACHE_TTL = 300; // seconds

    /**
     * Starter catalog. Used by the creating migration and by
     * FailureReasonCodeSeeder; both insert-if-absent, so an operator's
     * later edits (description, severity, is_active) are never reverted.
     *
     * @var array<int, array{code:string,category:string,description:string,severity:string}>
     */
    public const STARTER_CODES = [
        // ── source ───────────────────────────────────────────────
        ['code' => 'OGOS_NOT_FOUND', 'category' => self::CATEGORY_SOURCE, 'severity' => self::SEVERITY_WARNING,
         'description' => 'OGOS has no student record for this email. The profile RIS used to build the name may be stale or wrong.'],
        ['code' => 'OGOS_UNREACHABLE', 'category' => self::CATEGORY_SOURCE, 'severity' => self::SEVERITY_WARNING,
         'description' => 'OGOS could not be reached after retries, so no on-file snapshot could be compared.'],
        ['code' => 'ALUMNI_LOOKUP_FAILED', 'category' => self::CATEGORY_SOURCE, 'severity' => self::SEVERITY_WARNING,
         'description' => 'The alumni system returned no record (not found or unavailable; RIS cannot tell which), so no snapshot could be compared.'],
        ['code' => 'NO_SNAPSHOT', 'category' => self::CATEGORY_SOURCE, 'severity' => self::SEVERITY_INFO,
         'description' => 'No on-file snapshot is available for a reason not covered by a more specific source code.'],
        // ── data ─────────────────────────────────────────────────
        ['code' => 'PROFILE_DRIFT', 'category' => self::CATEGORY_DATA, 'severity' => self::SEVERITY_WARNING,
         'description' => 'The name OGOS currently holds differs from RIS\'s local copy, so the formats RIS tried may not reflect the name on the receipt.'],
        ['code' => 'ALL_CANDIDATES_EXHAUSTED', 'category' => self::CATEGORY_DATA, 'severity' => self::SEVERITY_INFO,
         'description' => 'Every name format RIS tried was rejected as not found. The receipt may carry a different spelling, a different name, or a different OR number.'],
        // ── format ───────────────────────────────────────────────
        ['code' => 'FMT_MISSING_MIDDLE', 'category' => self::CATEGORY_FORMAT, 'severity' => self::SEVERITY_INFO,
         'description' => 'OGOS lists a middle name that the local profile lacks; the cashier may have typed it on the receipt.'],
        ['code' => 'FMT_SUFFIX_IN_SURNAME', 'category' => self::CATEGORY_FORMAT, 'severity' => self::SEVERITY_INFO,
         'description' => 'A suffix (Jr., III, ...) appears inside the surname field, so the receipt may place it elsewhere.'],
        ['code' => 'FMT_NON_ASCII', 'category' => self::CATEGORY_FORMAT, 'severity' => self::SEVERITY_INFO,
         'description' => 'The name contains non-ASCII characters (e.g. ñ); the cashier may have typed a plain-ASCII form.'],
        ['code' => 'FMT_HYPHEN_OR_SPACING', 'category' => self::CATEGORY_FORMAT, 'severity' => self::SEVERITY_INFO,
         'description' => 'The name has a hyphen, a multi-word surname, or irregular spacing, which is typed inconsistently.'],
        ['code' => 'FMT_CASE_INCONSISTENT', 'category' => self::CATEGORY_FORMAT, 'severity' => self::SEVERITY_INFO,
         'description' => 'The name is stored all-upper or all-lower case (or differs from OGOS only by case); the receipt may use another casing.'],
    ];

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Codes an operator has switched off. Cached briefly: this is read by
     * a queue job per failure and changes rarely. Never throws — a cache
     * or DB problem must not stop a diagnosis row being written.
     *
     * @return array<int, string>
     */
    public static function inactiveCodes(): array
    {
        try {
            return Cache::remember(
                self::INACTIVE_CACHE_KEY,
                self::INACTIVE_CACHE_TTL,
                fn () => static::query()->where('is_active', false)->pluck('code')->all(),
            );
        } catch (\Throwable) {
            return [];
        }
    }

    public static function forgetInactiveCache(): void
    {
        Cache::forget(self::INACTIVE_CACHE_KEY);
    }
}
