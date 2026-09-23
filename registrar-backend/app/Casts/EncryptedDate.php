<?php

namespace App\Casts;

use App\Support\EncryptedPayload;
use Carbon\Carbon;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Undergrad Requestor Registration — Phase 6.
 *
 * Encryption-at-rest for a DATE column, while keeping the model-side
 * type contract callers already depend on: reads still hand back a
 * Carbon instance, so existing code such as
 * UndergradRequestorVerificationDetailResource's
 * `$profile?->date_of_birth?->toDateString()` keeps working unchanged.
 *
 * Laravel ships 'encrypted', 'encrypted:array', 'encrypted:json' and so
 * on, but no 'encrypted:date' — hence this small cast rather than a
 * config string. Values are normalised to Y-m-d before encryption so
 * the stored payload is stable regardless of whether the caller passed
 * a string, a Carbon, or a DateTime.
 *
 * Because ciphertext cannot live in a DATE column, the Phase 6
 * migration converts undergrad_requestor_profiles.date_of_birth to
 * TEXT. Nothing in this feature ever compares, sorts or ranges on that
 * column in SQL — it is display-only on the Admin review screen — so no
 * query capability is lost. If a future requirement DOES need to query
 * by date of birth, that requirement and this cast are mutually
 * exclusive; revisit the decision rather than working around it with a
 * second plaintext shadow column.
 */
class EncryptedDate implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?Carbon
    {
        $plain = EncryptedPayload::decode($value === null ? null : (string) $value);

        if ($plain === null || $plain === '') {
            return null;
        }

        try {
            return Carbon::parse($plain)->startOfDay();
        } catch (\Throwable) {
            // An unparseable value here means the underlying payload is
            // damaged (e.g. decrypted with the wrong key —
            // EncryptedPayload::decode() has already logged that).
            // Returning null keeps the Admin queue rendering instead of
            // 500-ing on one bad row.
            return null;
        }
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): array
    {
        if ($value === null || $value === '') {
            return [$key => null];
        }

        try {
            $normalised = Carbon::parse($value)->toDateString();
        } catch (\Throwable) {
            // Shape validation happens in
            // StoreUndergradRequestorRegistrationRequest ('date'), so
            // reaching here means a programmatic write with a bad value.
            // Store it verbatim (encrypted) rather than silently
            // dropping data an operator may need to investigate.
            $normalised = (string) $value;
        }

        return [$key => EncryptedPayload::encode($normalised)];
    }
}
