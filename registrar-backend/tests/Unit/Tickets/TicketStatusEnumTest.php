<?php

use App\Enums\TicketPriorityEnum;
use App\Enums\TicketStatusEnum;

/*
 * The transition table is the contract the state machine (Phase 2) enforces,
 * so it is pinned here in full: every legal move is listed, and every OTHER
 * pair is asserted illegal, so adding a transition by accident fails a test.
 */

const TK_LEGAL = [
    'open'           => ['in_progress', 'resolved'],
    'in_progress'    => ['pending_client', 'resolved'],
    'pending_client' => ['in_progress', 'resolved'],
    'resolved'       => ['closed', 'in_progress'],
    'closed'         => [],
];

test('every legal transition is allowed', function () {
    foreach (TK_LEGAL as $from => $targets) {
        foreach ($targets as $to) {
            expect(TicketStatusEnum::from($from)->canTransitionTo(TicketStatusEnum::from($to)))
                ->toBeTrue("{$from} -> {$to} should be legal");
        }
    }
});

test('every other transition is illegal, including staying in the same status', function () {
    foreach (TicketStatusEnum::cases() as $from) {
        foreach (TicketStatusEnum::cases() as $to) {
            if (in_array($to->value, TK_LEGAL[$from->value], true)) {
                continue;
            }

            expect($from->canTransitionTo($to))
                ->toBeFalse("{$from->value} -> {$to->value} should be illegal");
        }
    }
});

test('Closed is terminal and nothing leaves it', function () {
    expect(TicketStatusEnum::Closed->isTerminal())->toBeTrue()
        ->and(TicketStatusEnum::Closed->allowedTransitions())->toBe([]);

    foreach (TicketStatusEnum::cases() as $status) {
        if ($status !== TicketStatusEnum::Closed) {
            expect($status->isTerminal())->toBeFalse();
        }
    }
});

test('Resolved to In Progress is the only backward transition', function () {
    $returns = [];

    foreach (TicketStatusEnum::cases() as $from) {
        foreach ($from->allowedTransitions() as $to) {
            if ($from->isReturnTo($to)) {
                $returns[] = "{$from->value}->{$to->value}";
            }
        }
    }

    expect($returns)->toBe(['resolved->in_progress']);
});

test('only Open, In Progress and Pending Client are active', function () {
    expect(TicketStatusEnum::activeValues())
        ->toEqualCanonicalizing(['open', 'in_progress', 'pending_client'])
        ->and(TicketStatusEnum::Resolved->isActive())->toBeFalse()
        ->and(TicketStatusEnum::Closed->isActive())->toBeFalse();
});

test('status backing values are the stored strings and labels are readable', function () {
    expect(TicketStatusEnum::PendingClient->value)->toBe('pending_client')
        ->and(TicketStatusEnum::PendingClient->label())->toBe('Pending Client')
        ->and(TicketStatusEnum::InProgress->label())->toBe('In Progress');
});

test('priority weights order High above Medium above Low', function () {
    expect(TicketPriorityEnum::High->weight())->toBeGreaterThan(TicketPriorityEnum::Medium->weight())
        ->and(TicketPriorityEnum::Medium->weight())->toBeGreaterThan(TicketPriorityEnum::Low->weight());
});
