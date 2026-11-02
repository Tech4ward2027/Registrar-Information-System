<?php

use App\Enums\TicketStatusEnum;
use App\Models\Policy;
use App\Models\SystemUser;
use App\Models\Ticket;
use Illuminate\Foundation\Testing\RefreshDatabase;

require_once __DIR__ . '/../../Support/TicketTestHelpers.php';

uses(RefreshDatabase::class);

// ── Requesters ──────────────────────────────────────────────────────────────

test('the three requester roles can file tickets, staff cannot', function () {
    foreach ([SystemUser::ROLE_STUDENT, SystemUser::ROLE_ALUMNI, SystemUser::ROLE_UNDERGRAD_REQUESTOR] as $role) {
        expect(tkCan(SystemUser::factory()->create(['role_id' => $role]), 'create'))->toBeTrue();
    }

    expect(tkCan(tkStaff(['View', 'Claim', 'Reply', 'Resolve', 'Manage']), 'create'))->toBeFalse()
        ->and(tkCan(SystemUser::factory()->create(['role_id' => SystemUser::ROLE_SUPER_ADMIN]), 'create'))->toBeFalse();
});

test('a requester reads and acts on their own ticket only', function () {
    $owner  = SystemUser::factory()->create();
    $other  = SystemUser::factory()->create();
    $ticket = Ticket::factory()->create(['requester_user_id' => $owner->user_id]);

    foreach (['view', 'replyAsRequester', 'confirm', 'returnToProgress'] as $ability) {
        expect(tkCan($owner, $ability, $ticket))->toBeTrue("owner / {$ability}")
            ->and(tkCan($other, $ability, $ticket))->toBeFalse("other student / {$ability}");
    }
});

test('a student can never use staff abilities, even though hasModuleAccess() is true for them', function () {
    $student = SystemUser::factory()->create();
    $ticket  = Ticket::factory()->create(['requester_user_id' => $student->user_id]);

    // The trap this guards: hasModuleAccess() returns true for non-admins.
    expect($student->hasModuleAccess('tickets', 'Manage'))->toBeTrue();

    foreach (['claim', 'release', 'replyAsStaff', 'resolve'] as $ability) {
        expect(tkCan($student, $ability, $ticket))->toBeFalse($ability);
    }
    expect(tkCan($student, 'viewQueue'))->toBeFalse()
        ->and(tkCan($student, 'manage'))->toBeFalse();
});

test('a public ticket (no account) is not owned by any user', function () {
    $ticket = Ticket::factory()->public()->create();

    expect(tkCan(SystemUser::factory()->create(), 'view', $ticket))->toBeFalse();
});

// ── Staff ───────────────────────────────────────────────────────────────────

test('staff hold exactly the ticket actions their policy grants', function () {
    $ticket = Ticket::factory()->create();

    $viewer = tkStaff(['View']);
    expect(tkCan($viewer, 'view', $ticket))->toBeTrue()
        ->and(tkCan($viewer, 'viewQueue'))->toBeTrue()
        ->and(tkCan($viewer, 'claim', $ticket))->toBeFalse()
        ->and(tkCan($viewer, 'replyAsStaff', $ticket))->toBeFalse()
        ->and(tkCan($viewer, 'resolve', $ticket))->toBeFalse()
        ->and(tkCan($viewer, 'manage'))->toBeFalse();

    $worker = tkStaff(['View', 'Claim', 'Reply']);
    expect(tkCan($worker, 'claim', $ticket))->toBeTrue()
        ->and(tkCan($worker, 'replyAsStaff', $ticket))->toBeTrue()
        ->and(tkCan($worker, 'resolve', $ticket))->toBeFalse();

    expect(tkCan(tkStaff(['View', 'Resolve']), 'resolve', $ticket))->toBeTrue();
});

test('an admin with no tickets grant, or on the zero-access default, can do nothing', function () {
    $ticket = Ticket::factory()->create();
    $none   = SystemUser::factory()->create(['role_id' => SystemUser::ROLE_ADMIN, 'status' => 'Activated']);

    foreach (['view', 'claim', 'release', 'replyAsStaff', 'resolve'] as $ability) {
        expect(tkCan($none, $ability, $ticket))->toBeFalse($ability);
    }
    expect(tkCan($none, 'viewQueue'))->toBeFalse();
});

test('a super admin holds every staff ability', function () {
    $ticket = Ticket::factory()->create();
    $super  = SystemUser::factory()->create(['role_id' => SystemUser::ROLE_SUPER_ADMIN]);

    foreach (['view', 'claim', 'release', 'replyAsStaff', 'resolve'] as $ability) {
        expect(tkCan($super, $ability, $ticket))->toBeTrue($ability);
    }
    expect(tkCan($super, 'viewQueue'))->toBeTrue()
        ->and(tkCan($super, 'manage'))->toBeTrue();
});

test('release: the claimer may, another claimer may not, a manager may', function () {
    $claimer = tkStaff(['View', 'Claim']);
    $peer    = tkStaff(['View', 'Claim']);
    $manager = tkStaff(['View', 'Manage']);
    $ticket  = Ticket::factory()->claimedBy($claimer)->create();

    expect(tkCan($claimer, 'release', $ticket))->toBeTrue()
        ->and(tkCan($peer, 'release', $ticket))->toBeFalse()
        ->and(tkCan($manager, 'release', $ticket))->toBeTrue();
});

test('resolve: any holder of Resolve by default, only the claimer or a manager when configured', function () {
    $claimer = tkStaff(['View', 'Claim', 'Resolve']);
    $peer    = tkStaff(['View', 'Resolve']);
    $manager = tkStaff(['View', 'Resolve', 'Manage']);
    $ticket  = Ticket::factory()->claimedBy($claimer)->create();

    config(['tickets.resolve_requires_claimer' => false]);
    expect(tkCan($peer, 'resolve', $ticket))->toBeTrue();

    config(['tickets.resolve_requires_claimer' => true]);
    expect(tkCan($claimer, 'resolve', $ticket))->toBeTrue()
        ->and(tkCan($peer, 'resolve', $ticket))->toBeFalse()
        ->and(tkCan($manager, 'resolve', $ticket))->toBeTrue();
});

test('the policy does not look at ticket status: that is the state machine\'s job', function () {
    $staff  = tkStaff(['View', 'Reply']);
    $closed = Ticket::factory()->status(TicketStatusEnum::Closed)->create();

    expect(tkCan($staff, 'replyAsStaff', $closed))->toBeTrue();
});
