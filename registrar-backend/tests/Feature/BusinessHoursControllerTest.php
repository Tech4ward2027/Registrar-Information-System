<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['app.display_timezone' => 'Asia/Manila']);
});

test('is reachable without authentication', function () {
    // No Sanctum::actingAs(...) here on purpose — this is the one endpoint
    // in the app that must work for a requester who hasn't logged in yet.
    Carbon::setTestNow(Carbon::parse('2026-03-10 10:00:00', 'Asia/Manila')); // Tue 10 AM

    $this->getJson('/api/v1/business-hours/status')
        ->assertOk()
        ->assertJsonStructure(['is_open', 'next_open_at', 'closes_at', 'timezone']);

    Carbon::setTestNow();
});

test('returns is_open true with a closes_at during office hours', function () {
    Carbon::setTestNow(Carbon::parse('2026-03-10 10:00:00', 'Asia/Manila')); // Tue 10 AM

    $this->getJson('/api/v1/business-hours/status')
        ->assertOk()
        ->assertJson([
            'is_open'      => true,
            'next_open_at' => null,
            'timezone'     => 'Asia/Manila',
        ])
        ->assertJsonPath('closes_at', fn ($value) => str_starts_with($value, '2026-03-10T20:00:00'));

    Carbon::setTestNow();
});

test('returns is_open false with next_open_at outside office hours', function () {
    Carbon::setTestNow(Carbon::parse('2026-03-14 12:00:00', 'Asia/Manila')); // Saturday

    $this->getJson('/api/v1/business-hours/status')
        ->assertOk()
        ->assertJson([
            'is_open'   => false,
            'closes_at' => null,
        ])
        ->assertJsonPath('next_open_at', fn ($value) => str_starts_with($value, '2026-03-16T08:00:00')); // Monday

    Carbon::setTestNow();
});