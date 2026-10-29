<?php

/*
 * Helpers shared by the ticketing tests. Required (once) by each test file that
 * needs them, because Pest loads every test file into one process and a
 * function defined in two files is a fatal error.
 */

use App\Models\Policy;
use App\Models\SystemUser;
use App\Models\Ticket;
use App\Models\TicketCategory;
use App\Services\Tickets\TicketService;
use Illuminate\Support\Facades\Gate;

/** An admin whose policy grants exactly the given ticket actions. */
function tkStaff(array $actions = ['View', 'Claim', 'Reply', 'Resolve'], int $role = SystemUser::ROLE_ADMIN): SystemUser
{
    $policy = Policy::create([
        'name' => 'tk-' . bin2hex(random_bytes(4)), 'is_system' => false,
        'permissions' => ['tickets' => $actions],
    ]);

    return SystemUser::factory()->create([
        'role_id' => $role, 'status' => 'Activated', 'policy_id' => $policy->policy_id,
    ]);
}

function tkCan(SystemUser $user, string $ability, mixed $arg = null): bool
{
    return $arg === null
        ? Gate::forUser($user)->allows($ability, Ticket::class)
        : Gate::forUser($user)->allows($ability, $arg);
}

function tkService(): TicketService
{
    return app(TicketService::class);
}

function tkCategory(string $code = 'CAT-02'): TicketCategory
{
    return TicketCategory::where('code', $code)->firstOrFail();
}

/** A ticket created through the real service, so it has its message and events. */
function tkOpenTicket(?SystemUser $requester = null, string $categoryCode = 'CAT-02'): Ticket
{
    $requester ??= SystemUser::factory()->create();

    return tkService()->createForUser(
        $requester, tkCategory($categoryCode), 'Receipt not accepted', 'My official receipt was rejected at the counter.', '203.0.113.9',
    );
}

/** The ticket's event types, in order. */
function tkEventTypes(Ticket $ticket): array
{
    return $ticket->events()->pluck('event_type')->all();
}
