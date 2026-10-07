<?php

namespace App\Enums;

/**
 * Single source of truth for the self-declared gender of an Undergrad
 * Requestor (undergrad_requestor_profiles.gender).
 *
 * Backs a plain string column rather than a DB enum type, same convention
 * as RequestChannelEnum / RequestStatusEnum: adding a value later is a code
 * change here, not an ALTER TABLE on a populated table.
 *
 * The two values deliberately match the Male/Female vocabulary already used
 * by student_profile.sex_at_birth and alumni_profile.sex_at_birth, so the
 * Logbook renders one consistent set of values for every requester type.
 */
enum GenderEnum: string
{
    case Male   = 'Male';
    case Female = 'Female';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Canonicalises client input ("male", " FEMALE ") to a valid case value.
     * Empty input becomes null; anything else that cannot match is returned
     * trimmed but otherwise untouched, so validation — not
     * this helper — remains the thing that rejects bad input.
     */
    public static function normalize(mixed $value): mixed
    {
        if (!is_string($value)) {
            return $value;
        }

        $trimmed = trim($value);

        if ($trimmed === '') {
            return null;
        }

        $canonical = ucfirst(strtolower($trimmed));

        return self::tryFrom($canonical) !== null ? $canonical : $trimmed;
    }
}
