<?php

namespace App\Casts;

use App\Support\EncryptedPayload;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Undergrad Requestor Registration — Phase 6.
 *
 * Column-level encryption-at-rest for a free-text PII column.
 *
 * Deliberately NOT Laravel's built-in 'encrypted' cast (which
 * SystemUser::$casts uses for idp_access_token). The built-in cast
 * throws DecryptException the moment it meets a value that was written
 * before encryption was switched on — which is precisely the state
 * every existing row in undergrad_requestor_profiles is in until the
 * Phase 6 backfill migration has run, and the state every row is in
 * again if config('undergrad_requestor.pii_encryption.enabled') is ever
 * turned off. This cast tolerates that transition in both directions;
 * see App\Support\EncryptedPayload for the full reasoning and for the
 * loud-failure handling of a genuine APP_KEY mismatch.
 *
 * Only ever apply this to columns nothing queries on. An encrypted
 * column cannot be indexed, sorted, or LIKE-searched — ciphertext for
 * the same plaintext differs on every write (random IV). The Admin
 * verification queue's search deliberately covers only plaintext
 * columns (email, student_number, first_name, last_name) for exactly
 * this reason.
 *
 * Storage: ciphertext is materially longer than its plaintext (a short
 * string becomes ~250 characters), so every column using this cast must
 * be TEXT, not a tight VARCHAR. The Phase 6 migration widens the two
 * columns that needed it.
 */
class EncryptedText implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return EncryptedPayload::decode($value === null ? null : (string) $value);
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): array
    {
        return [$key => EncryptedPayload::encode($value === null ? null : (string) $value)];
    }
}
