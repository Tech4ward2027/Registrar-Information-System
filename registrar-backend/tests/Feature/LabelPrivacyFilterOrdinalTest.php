<?php

/**
 * Ordinals in the privacy filter.
 *
 * "2nd" is the only thing that separates "Certificate of Graduation" from
 * "Certificate of Graduation - 2nd copy". It identifies nobody, so it must
 * survive, while every identifier-shaped value must still be removed.
 *
 * All values are synthetic. Helpers are prefixed `lpo_` (Pest helpers are global).
 */

use App\Services\LabelPrivacyFilter;
use App\Services\LabelReranker;

function lpo_vocabulary(): array
{
    // "2nd" is deliberately NOT in any candidate name.
    return LabelPrivacyFilter::vocabulary(['Diploma - copy', 'Certificate of Graduation - copy']);
}

function lpo_scrub(string $text): string
{
    return LabelPrivacyFilter::scrub($text, lpo_vocabulary());
}

test('ordinal: a short ordinal survives even when no candidate name contains it', function (string $ordinal) {
    expect(lpo_scrub("Diploma {$ordinal} copy"))->toBe("Diploma {$ordinal} copy");
})->with(['1st', '2nd', '3rd', '4th', '11th', '21st', '2ND']);

test('ordinal: the real-world label keeps its qualifier', function () {
    expect(lpo_scrub('Certificate of Graduation - 2nd copy'))->toBe('Certificate of Graduation - 2nd copy');
});

test('ordinal: identifier-shaped and mixed values are still removed', function (string $value) {
    expect(lpo_scrub("Diploma {$value} copy"))->toBe('Diploma copy');
})->with([
    'student number'          => '2021-00123',
    'reference number'        => 'REF-45',
    'student-number fragment' => 'S2021',
    'ordinal plus digits'     => '2nd2',
    'three-digit ordinal'     => '123rd',
    'digit-led mixed token'   => '99th9',
    'OR number'               => 'OR#778899',
]);

test('ordinal: redact keeps it in candidate names and still removes identifiers', function () {
    expect(LabelPrivacyFilter::redact('Diploma - 2nd copy'))->toBe('Diploma - 2nd copy')
        ->and(LabelPrivacyFilter::redact('Diploma - 2nd copy 2021-00123'))->toBe('Diploma - 2nd copy');
});

test('ordinal: plain numbers behave as before', function () {
    expect(lpo_scrub('Diploma 2 copy'))->toBe('Diploma copy')   // not in vocabulary, never inferred
        ->and(LabelPrivacyFilter::redact('Form 137'))->toBe('Form 137')
        ->and(LabelPrivacyFilter::redact('Form 1234567'))->toBe('Form');
});

test('ordinal: it reaches the outbound request while personal data does not', function () {
    $candidates = [
        ['key' => 'c6', 'type' => 'certificate', 'name' => 'Certificate of Graduation',
            'patterns' => ['Certificate of Graduation - 2nd copy', 'Juan Dela Cruz']],
        ['key' => 'c7', 'type' => 'certificate', 'name' => 'Certificate of Graduation (Copy)', 'patterns' => []],
    ];

    $payload = app(LabelReranker::class)->buildPayload(
        'Certificate of Graduation - 2nd copy 2021-00123 Juan Dela Cruz',
        $candidates,
    );
    $user = $payload['messages'][0]['content'];

    expect($user)->toContain('<label>Certificate of Graduation - 2nd copy</label>')
        ->and($user)->toContain('"patterns":["Certificate of Graduation - 2nd copy"]')
        ->and($user)->not->toContain('Juan')
        ->and($user)->not->toContain('Dela Cruz')
        ->and($user)->not->toContain('2021-00123');
});
