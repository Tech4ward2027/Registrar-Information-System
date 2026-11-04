<?php

use App\Enums\TicketStatusEnum;
use App\Exceptions\Tickets\TicketTransitionException;
use App\Models\Ticket;
use App\Services\Tickets\TicketStateMachine;

// No RefreshDatabase on purpose: these tests never touch the database, which is
// also what lets the "outside a transaction" guard be observed at level 0.

function tkMachine(): TicketStateMachine
{
    return new TicketStateMachine();
}

test('the machine allows exactly the transitions in the enum table and refuses every other pair', function () {
    foreach (TicketStatusEnum::cases() as $from) {
        foreach (TicketStatusEnum::cases() as $to) {
            $legal = in_array($to, $from->allowedTransitions(), true);

            expect(tkMachine()->canTransition($from, $to))->toBe($legal, "{$from->value} -> {$to->value}");

            if ($legal) {
                tkMachine()->assertCanTransition($from, $to);
            } else {
                expect(fn () => tkMachine()->assertCanTransition($from, $to))
                    ->toThrow(TicketTransitionException::class);
            }
        }
    }
});

test('an illegal transition reports both statuses', function () {
    try {
        tkMachine()->assertCanTransition(TicketStatusEnum::Closed, TicketStatusEnum::Open);
        $this->fail('expected an exception');
    } catch (TicketTransitionException $e) {
        expect($e->from)->toBe(TicketStatusEnum::Closed)
            ->and($e->to)->toBe(TicketStatusEnum::Open)
            ->and($e->getMessage())->toBe('A ticket cannot move from Closed to Open.');
    }
});

test('a status change outside a transaction is refused before anything is written', function () {
    $ticket = new Ticket();
    $ticket->status = TicketStatusEnum::Open;

    expect(fn () => tkMachine()->transition($ticket, TicketStatusEnum::InProgress))
        ->toThrow(LogicException::class, 'inside a transaction');
});

test('claiming only ever starts work on an Open ticket and is legal for every claimable status', function () {
    $machine = tkMachine();

    expect($machine->claimTarget(TicketStatusEnum::Open))->toBe(TicketStatusEnum::InProgress)
        ->and($machine->claimTarget(TicketStatusEnum::InProgress))->toBe(TicketStatusEnum::InProgress)
        ->and($machine->claimTarget(TicketStatusEnum::PendingClient))->toBe(TicketStatusEnum::PendingClient)
        ->and($machine->claimableStatuses())->toEqualCanonicalizing([
            TicketStatusEnum::Open, TicketStatusEnum::InProgress, TicketStatusEnum::PendingClient,
        ]);
});
