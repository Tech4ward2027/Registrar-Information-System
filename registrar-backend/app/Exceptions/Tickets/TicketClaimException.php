<?php

namespace App\Exceptions\Tickets;

/**
 * A claim or release was refused.
 *
 *   AlreadyClaimed - someone else holds the ticket (typically a lost race).
 *                    $claimerId says who, so the UI can say "already yours"
 *                    or "Staff X is handling this".
 *   NotClaimed     - release of a ticket nobody holds.
 *   NotPermitted   - release by someone who is neither the claimer nor
 *                    allowed to override.
 */
class TicketClaimException extends TicketException
{
    public const ALREADY_CLAIMED = 'already_claimed';
    public const NOT_CLAIMED     = 'not_claimed';
    public const NOT_PERMITTED   = 'not_permitted';

    private function __construct(
        public readonly string $reason,
        public readonly ?int $claimerId,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function alreadyClaimed(int $claimerId): self
    {
        return new self(self::ALREADY_CLAIMED, $claimerId, 'This ticket has already been claimed.');
    }

    public static function notClaimed(): self
    {
        return new self(self::NOT_CLAIMED, null, 'This ticket is not claimed.');
    }

    public static function notPermitted(?int $claimerId): self
    {
        return new self(self::NOT_PERMITTED, $claimerId, 'Only the staff member handling this ticket can release it.');
    }
}
