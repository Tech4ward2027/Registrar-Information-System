<?php

namespace App\Policies;

use App\Models\SystemUser;
use App\Models\Ticket;

/**
 * Support Ticketing — who may do what.
 *
 * Two kinds of caller, kept strictly apart:
 *
 *   Requesters (Student, Alumni, Undergrad Requestor) act only on tickets they
 *   own. They are never gated by the policy-module system.
 *
 *   Staff (Admin, Super Admin) act through the 'tickets' module of Policy
 *   Management: View, Claim, Reply, Resolve, Manage. Super Admin holds every
 *   action. Note that SystemUser::hasModuleAccess() returns true for anyone who
 *   is not an Admin, students included, so every staff check below tests
 *   isStaff() first. Dropping that guard would hand staff actions to students.
 *
 * Public (magic-link) submitters have no account, so no policy applies to them;
 * the Phase 4 flow authorises by token and calls TicketService directly.
 *
 * These methods answer identity and permission only. Whether the action fits
 * the ticket's current status (cannot reply to a Closed ticket, ...) is the
 * state machine's and TicketService's job.
 */
class TicketPolicy
{
    private const REQUESTER_ROLES = [
        SystemUser::ROLE_STUDENT,
        SystemUser::ROLE_ALUMNI,
        SystemUser::ROLE_UNDERGRAD_REQUESTOR,
    ];

    // ── Requester side ──────────────────────────────────────────────────────

    /** May this kind of account file tickets and list its own. */
    public function create(SystemUser $user): bool
    {
        return $this->isRequester($user);
    }

    public function viewOwn(SystemUser $user): bool
    {
        return $this->isRequester($user);
    }

    /**
     * Read one ticket: its owner, or staff who can see the queue.
     */
    public function view(SystemUser $user, Ticket $ticket): bool
    {
        return $this->owns($user, $ticket) || $this->staffCan($user, 'View');
    }

    public function replyAsRequester(SystemUser $user, Ticket $ticket): bool
    {
        return $this->owns($user, $ticket);
    }

    /** Confirm the resolution. */
    public function confirm(SystemUser $user, Ticket $ticket): bool
    {
        return $this->owns($user, $ticket);
    }

    /** "This isn't resolved". */
    public function returnToProgress(SystemUser $user, Ticket $ticket): bool
    {
        return $this->owns($user, $ticket);
    }

    // ── Staff side ──────────────────────────────────────────────────────────

    /** See the shared queue. */
    public function viewQueue(SystemUser $user): bool
    {
        return $this->staffCan($user, 'View');
    }

    public function claim(SystemUser $user, Ticket $ticket): bool
    {
        return $this->staffCan($user, 'Claim');
    }

    /**
     * Release a claim: the claimer (who must still hold Claim), or staff who
     * can Manage tickets, to free a ticket from an absent colleague.
     */
    public function release(SystemUser $user, Ticket $ticket): bool
    {
        if ($this->staffCan($user, 'Manage')) {
            return true;
        }

        return $this->staffCan($user, 'Claim')
            && $ticket->claimed_by_user_id !== null
            && (int) $ticket->claimed_by_user_id === (int) $user->user_id;
    }

    public function replyAsStaff(SystemUser $user, Ticket $ticket): bool
    {
        return $this->staffCan($user, 'Reply');
    }

    /**
     * Resolve. Any staff holding Resolve may, by default. With
     * config 'tickets.resolve_requires_claimer' on, only the current claimer
     * (or a Manager) may. This method is the single place that open policy
     * question is read.
     */
    public function resolve(SystemUser $user, Ticket $ticket): bool
    {
        if (! $this->staffCan($user, 'Resolve')) {
            return false;
        }

        if (! config('tickets.resolve_requires_claimer')) {
            return true;
        }

        return $this->staffCan($user, 'Manage')
            || ($ticket->claimed_by_user_id !== null && (int) $ticket->claimed_by_user_id === (int) $user->user_id);
    }

    /** Categories, SLA and volume reports, log export, spam handling. */
    public function manage(SystemUser $user): bool
    {
        return $this->staffCan($user, 'Manage');
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    private function isRequester(SystemUser $user): bool
    {
        return in_array((int) $user->role_id, self::REQUESTER_ROLES, true);
    }

    private function owns(SystemUser $user, Ticket $ticket): bool
    {
        return $this->isRequester($user)
            && $ticket->requester_user_id !== null
            && (int) $ticket->requester_user_id === (int) $user->user_id;
    }

    private function staffCan(SystemUser $user, string $action): bool
    {
        return $user->isStaff() && $user->hasModuleAccess('tickets', $action);
    }
}
