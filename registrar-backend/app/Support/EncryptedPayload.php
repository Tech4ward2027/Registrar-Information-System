<?php

namespace App\Support;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;

/**
 * Undergrad Requestor Registration — Phase 6.
 *
 * The single implementation of "encrypt this column value at rest,
 * tolerate a row that predates encryption." Used by the two cast
 * classes (EncryptedText, EncryptedDate) AND by the one-time backfill
 * inside 2026_09_15_000001_encrypt_undergrad_requestor_profile_pii.php,
 * so the migration can never encrypt a value in a subtly different
 * shape from the one the model will later try to read.
 *
 * ── Why column-level encryption here, when nothing else in this
 *    codebase encrypts PII ──────────────────────────────────────────
 *
 * Phase 6 of the plan asks us to check what encryption-at-rest standard
 * student_profile.date_of_birth (and equivalents) get today, match it
 * "at minimum", and flag the existing standard to leadership if it
 * looks insufficient rather than silently inheriting a weak precedent.
 *
 * What we found: nothing in student_profile, student_contact_information
 * or student_address is encrypted. The one existing precedent for
 * column-level encryption in this codebase is SystemUser's
 * 'idp_access_token' => 'encrypted' cast — so the capability and the
 * convention both already exist; they have simply never been applied to
 * personal data.
 *
 * Why this table goes further than that precedent rather than matching
 * it, even though matching would have satisfied the letter of the plan:
 *
 *   1. Provenance. OGOS-owned mirrors are copies of a record that lives,
 *      authoritatively, in another system that has its own controls.
 *      undergrad_requestor_profiles is the ONLY copy of this data —
 *      RIS is the system of record for it.
 *   2. Collection surface. This is the one place RIS accepts PII from a
 *      completely unauthenticated public form (see the threat-model
 *      note, docs/undergrad-requestor-threat-model.md).
 *   3. Cost of doing it. The four encrypted columns are display-only:
 *      nothing sorts, filters, joins or indexes on them (the Admin
 *      queue's search covers email / student_number / first_name /
 *      last_name, all of which stay plaintext deliberately). So the
 *      usual reason not to encrypt a column simply does not apply.
 *
 * This is deliberately NOT a silent unilateral upgrade of the whole
 * schema: student_profile is untouched. The gap there is written up for
 * leadership in the threat-model note instead, which is what the plan
 * asked for.
 *
 * ── Operational warning ───────────────────────────────────────────────
 * These values are recoverable only with APP_KEY. Rotating APP_KEY
 * without running Laravel's key:rotate-style re-encryption first makes
 * them permanently unreadable. decode() below is written so that this
 * failure mode is LOUD (a warning log per affected read) rather than
 * silently returning ciphertext that looks like corrupted data.
 */
final class EncryptedPayload
{
    /**
     * Encrypt a value for storage.
     *
     * - null passes through untouched (nullable columns stay nullable).
     * - An already-encrypted value passes through untouched, so a
     *   double save() — or the backfill running twice — can never
     *   produce doubly-wrapped ciphertext.
     * - With encryption disabled by config, the value is stored as-is.
     *   Reads still work either way, because decode() tolerates
     *   plaintext; that is what makes the feature flag safe to flip in
     *   both directions without a data migration.
     */
    public static function encode(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return $value;
        }

        if (!self::enabled()) {
            return $value;
        }

        if (self::looksEncrypted($value)) {
            return $value;
        }

        return Crypt::encryptString($value);
    }

    /**
     * Decrypt a stored value.
     *
     * Three cases, deliberately distinguished:
     *
     *  - Plaintext (a row written before this phase, or with encryption
     *    switched off): returned as-is, no log noise. This is the
     *    expected state for legacy rows and is not an error.
     *  - Valid ciphertext: decrypted.
     *  - Something that structurally IS one of our encrypted payloads
     *    but will not decrypt: returned as-is AND logged at warning
     *    level. That combination almost always means APP_KEY changed,
     *    which is an operational incident — it must not be swallowed
     *    into a field that merely renders oddly in the Admin UI.
     */
    public static function decode(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return $value;
        }

        try {
            return Crypt::decryptString($value);
        } catch (DecryptException $e) {
            if (self::hasEncryptedPayloadShape($value)) {
                Log::warning('[EncryptedPayload] value has encrypted-payload shape but failed to decrypt — check APP_KEY rotation', [
                    'error' => $e->getMessage(),
                ]);
            }

            // Plaintext legacy value (or an unrecoverable one). Returning
            // it beats throwing: a reviewer seeing a stale-looking value
            // is recoverable; a 500 on the Admin verification queue is a
            // self-inflicted outage.
            return $value;
        }
    }

    /**
     * True only if this value both looks like one of our payloads AND
     * decrypts with the current key. Used by the backfill to decide
     * whether a row still needs encrypting.
     */
    public static function looksEncrypted(string $value): bool
    {
        if (!self::hasEncryptedPayloadShape($value)) {
            return false;
        }

        try {
            Crypt::decryptString($value);

            return true;
        } catch (DecryptException) {
            return false;
        }
    }

    public static function enabled(): bool
    {
        return (bool) config('undergrad_requestor.pii_encryption.enabled', true);
    }

    /**
     * Cheap structural check — a Laravel encrypted string is base64 of a
     * JSON object carrying iv/value/mac. Used to tell "this was never
     * encrypted" apart from "this was encrypted with a key we no longer
     * have", which is the difference between a non-event and an incident.
     */
    private static function hasEncryptedPayloadShape(string $value): bool
    {
        $decoded = base64_decode($value, true);

        if ($decoded === false) {
            return false;
        }

        $payload = json_decode($decoded, true);

        return is_array($payload)
            && array_key_exists('iv', $payload)
            && array_key_exists('value', $payload)
            && array_key_exists('mac', $payload);
    }
}
