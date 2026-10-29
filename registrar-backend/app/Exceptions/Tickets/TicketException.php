<?php

namespace App\Exceptions\Tickets;

use RuntimeException;

/**
 * Base class for every expected, business-rule refusal raised by the ticketing
 * services (illegal transition, lost claim race, return limit, ...).
 *
 * These are not bugs and not authorization failures: the caller asked for
 * something the ticket's current state does not allow. The API layer (Phase 3)
 * maps them to 409/422 responses; catching this one type is enough to tell
 * "refused by the rules" apart from a real fault.
 */
abstract class TicketException extends RuntimeException
{
}
