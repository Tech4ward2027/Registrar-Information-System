<?php

use App\Enums\UndergradRequestorVerificationStatusEnum;

/**
 * Undergrad Requestor Registration — Phase 7.
 *
 * Pure, DB-free coverage of the enum's cases, backing values and
 * labels. The lifecycle rules the enum's own docblock documents
 * (Pending -> Approved, Pending -> Rejected, no automated transitions)
 * are exercised where they actually take effect — UserProvisioningService
 * and the Admin approve/reject endpoints — in
 * tests/Feature/UndergradRequestorProvisioningTest.php and
 * tests/Feature/UndergradRequestorVerificationTest.php. This file only
 * verifies the enum's own, static contract.
 */

test('the enum has exactly the three cases D6 specifies, in a stable order', function () {
    $cases = UndergradRequestorVerificationStatusEnum::cases();

    expect($cases)->toHaveCount(3);
    expect(array_map(fn ($case) => $case->name, $cases))
        ->toBe(['Pending', 'Approved', 'Rejected']);
});

test('each case is backed by the exact string value the database column stores', function () {
    expect(UndergradRequestorVerificationStatusEnum::Pending->value)->toBe('Pending');
    expect(UndergradRequestorVerificationStatusEnum::Approved->value)->toBe('Approved');
    expect(UndergradRequestorVerificationStatusEnum::Rejected->value)->toBe('Rejected');
});

test('from() round-trips a stored string back into the matching case', function () {
    expect(UndergradRequestorVerificationStatusEnum::from('Pending'))
        ->toBe(UndergradRequestorVerificationStatusEnum::Pending);
    expect(UndergradRequestorVerificationStatusEnum::from('Approved'))
        ->toBe(UndergradRequestorVerificationStatusEnum::Approved);
    expect(UndergradRequestorVerificationStatusEnum::from('Rejected'))
        ->toBe(UndergradRequestorVerificationStatusEnum::Rejected);
});

test('from() rejects any value outside the three known statuses', function () {
    UndergradRequestorVerificationStatusEnum::from('Approved ');
})->throws(\ValueError::class);

test('tryFrom() returns null instead of throwing for an unknown value', function () {
    expect(UndergradRequestorVerificationStatusEnum::tryFrom('Bogus'))->toBeNull();
    expect(UndergradRequestorVerificationStatusEnum::tryFrom(''))->toBeNull();
});

test('label() gives every case a distinct, human-readable string', function () {
    expect(UndergradRequestorVerificationStatusEnum::Pending->label())->toBe('Pending Review');
    expect(UndergradRequestorVerificationStatusEnum::Approved->label())->toBe('Approved');
    expect(UndergradRequestorVerificationStatusEnum::Rejected->label())->toBe('Rejected');

    $labels = array_map(
        fn (UndergradRequestorVerificationStatusEnum $case) => $case->label(),
        UndergradRequestorVerificationStatusEnum::cases(),
    );

    // A duplicate label would let two different statuses render
    // identically on the Admin queue's status badge — cheap to guard
    // against permanently here.
    expect($labels)->toBe(array_unique($labels));
});
