<?php

namespace App\Exceptions\Tickets;

use App\Models\SystemUser;
use App\Support\Tickets\ActorName;
use Illuminate\Http\JsonResponse;

/**
 * Turns a business-rule refusal from the ticketing services into an HTTP
 * response. Registered once in bootstrap/app.php, so controllers contain no
 * try/catch and every endpoint refuses in the same shape:
 *
 *   { "message": "...", "code": "already_claimed", ...extra }
 *
 * `code` is the stable, machine-readable part the frontend switches on;
 * `message` is human-readable and safe to show.
 *
 * Status codes
 *   409  the ticket's CURRENT STATE does not allow this (lost claim race,
 *        wrong status, return limit). The request was well-formed; retrying
 *        the same request cannot succeed until the state changes.
 *   403  the caller may not do this to this ticket (release by a non-claimer).
 *
 * Input that is merely invalid is a 422 from the Form Requests and never
 * reaches here.
 */
final class TicketExceptionRenderer
{
    public static function render(TicketException $e): JsonResponse
    {
        return match (true) {
            $e instanceof TicketClaimException => self::claim($e),

            $e instanceof TicketReturnLimitException => response()->json([
                'message' => $e->getMessage(),
                'code'    => 'return_limit_reached',
                'limit'   => $e->limit,
            ], 409),

            $e instanceof TicketTransitionException => response()->json([
                'message' => $e->getMessage(),
                'code'    => 'invalid_transition',
                'from'    => $e->from->value,
                'to'      => $e->to->value,
            ], 409),

            $e instanceof TicketActionNotAllowedException => response()->json([
                'message' => $e->getMessage(),
                'code'    => 'action_not_allowed',
                'action'  => $e->action,
                'status'  => $e->status->value,
            ], 409),

            // A future TicketException subclass nobody mapped yet: still a
            // refusal by the rules, never a 500.
            default => response()->json(['message' => $e->getMessage(), 'code' => 'ticket_rule_violation'], 409),
        };
    }

    private static function claim(TicketClaimException $e): JsonResponse
    {
        return match ($e->reason) {
            TicketClaimException::ALREADY_CLAIMED => response()->json([
                'message'    => $e->getMessage(),
                'code'       => 'already_claimed',
                // Only staff routes can reach a claim refusal, so naming the
                // winner is fine and is what lets the UI say who has it.
                'claimed_by' => self::claimer($e->claimerId),
            ], 409),

            TicketClaimException::NOT_CLAIMED => response()->json([
                'message' => $e->getMessage(),
                'code'    => 'not_claimed',
            ], 409),

            default => response()->json([
                'message'    => $e->getMessage(),
                'code'       => 'claim_not_permitted',
                'claimed_by' => self::claimer($e->claimerId),
            ], 403),
        };
    }

    /** @return array{id:int,name:string}|null */
    private static function claimer(?int $userId): ?array
    {
        if ($userId === null) {
            return null;
        }

        $user = SystemUser::query()->with(['adminProfile', 'studentProfile'])->find($userId);

        return ['id' => $userId, 'name' => ActorName::staff($user)];
    }
}
