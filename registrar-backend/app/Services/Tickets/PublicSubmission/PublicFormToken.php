<?php

namespace App\Services\Tickets\PublicSubmission;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;

/**
 * The "minimum fill time" control.
 *
 * Loading the public form returns a token that is just the issue time,
 * encrypted (so authenticated: it cannot be forged or edited). Submitting
 * sends it back, and the age tells us how long the form was open. A bot that
 * posts instantly, never loaded the form, or replays a stale token is caught
 * here; a client-supplied timestamp could not do that, since a bot can claim
 * any time it likes.
 *
 * It proves elapsed time only, not identity. It is one layer among several.
 */
class PublicFormToken
{
    public function issue(): string
    {
        return Crypt::encryptString((string) time());
    }

    /** Seconds since issue, or null when the token is missing, altered or not ours. */
    public function ageSeconds(?string $token): ?int
    {
        if ($token === null || $token === '') {
            return null;
        }

        try {
            $issued = Crypt::decryptString($token);
        } catch (DecryptException) {
            return null;
        }

        if (! ctype_digit($issued)) {
            return null;
        }

        return max(0, time() - (int) $issued);
    }
}
