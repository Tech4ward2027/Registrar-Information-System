<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Generates claim credentials (uuid + short typeable code) for a claimable
 * thing: a request, one of its documents/certificates, or (legacy) a
 * release group.
 *
 * The 6-character code is checked for uniqueness against ALL of those
 * tables, not just the caller's own. A code is typed by staff without
 * saying which kind of ticket it is, so two tables holding the same code
 * would make it ambiguous. Plain DB::table() is used on purpose: it sees
 * archived and soft-deleted rows, so a code is never reused either.
 *
 * Alphabet and length match DocumentRequest's (no 0/O, 1/I/L), so codes
 * from every table look and behave the same at the counter.
 */
final class ClaimCredential
{
    public const ALPHABET = '23456789ABCDEFGHJKMNPQRSTVWXYZ';
    public const LENGTH   = 6;

    /** Every table that can hold a claim_code. */
    public const TABLES = [
        'document_request',
        'request_document',
        'request_certificate',
        'request_release_group',
    ];

    public static function uuid(): string
    {
        return (string) Str::uuid();
    }

    public static function code(): string
    {
        $max = strlen(self::ALPHABET) - 1;

        do {
            $code = '';
            for ($i = 0; $i < self::LENGTH; $i++) {
                $code .= self::ALPHABET[random_int(0, $max)];
            }
        } while (self::codeExists($code));

        return $code;
    }

    public static function codeExists(string $code): bool
    {
        foreach (self::TABLES as $table) {
            if (DB::table($table)->where('claim_code', $code)->exists()) {
                return true;
            }
        }

        return false;
    }
}
