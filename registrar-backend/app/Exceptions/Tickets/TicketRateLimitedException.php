<?php

namespace App\Exceptions\Tickets;

/**
 * A public-submission ceiling was reached (per address, per IP, per day).
 * Rendered as 429 with Retry-After by TicketExceptionRenderer, the same shape
 * the named route limiters produce, so the frontend handles both identically.
 */
class TicketRateLimitedException extends TicketException
{
    public function __construct(public readonly int $retryAfterSeconds, public readonly string $scope)
    {
        parent::__construct('Too many requests. Please try again later.');
    }
}
