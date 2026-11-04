<?php

use App\Enums\TicketPriorityEnum;
use App\Models\Ticket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

test('the sortable weight follows the priority on create', function () {
    expect(Ticket::factory()->priority(TicketPriorityEnum::High)->create()->priority_weight)->toBe(3)
        ->and(Ticket::factory()->priority(TicketPriorityEnum::Medium)->create()->priority_weight)->toBe(2)
        ->and(Ticket::factory()->priority(TicketPriorityEnum::Low)->create()->priority_weight)->toBe(1);
});

test('the weight is recomputed when the priority changes, and left alone otherwise', function () {
    $ticket = Ticket::factory()->priority(TicketPriorityEnum::Low)->create();

    $ticket->forceFill(['priority' => TicketPriorityEnum::High])->save();
    expect($ticket->fresh()->priority_weight)->toBe(3);

    $ticket->forceFill(['subject' => 'Changed subject'])->save();
    expect($ticket->fresh()->priority_weight)->toBe(3);
});

test('the queue index exists', function () {
    expect(Schema::hasColumn('tickets', 'priority_weight'))->toBeTrue();

    $indexes = collect(Schema::getIndexes('tickets'))->pluck('name')->all();
    expect($indexes)->toContain('idx_tickets_queue_weight');
});
