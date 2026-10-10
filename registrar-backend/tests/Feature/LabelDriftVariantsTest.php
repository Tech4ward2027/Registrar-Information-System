<?php

use App\Services\CashierLabelNormalizer;
use App\Support\LabelDriftVariants;

test('every variant differs from the original after normalisation', function (string $label) {
    foreach (LabelDriftVariants::for($label) as $variant) {
        expect(CashierLabelNormalizer::normalize($variant))->not->toBe(CashierLabelNormalizer::normalize($label))
            ->and(CashierLabelNormalizer::normalize($variant))->not->toBe('');
    }
})->with([
    'Certification Fee - Latin Honors',
    'Certified True Copy - Diploma',
    'Informative Copy of Grades',
    'Transcript of Records (OU)',
    'Diploma',
]);

test('known drift kinds are produced for a prefixed, two-part label', function () {
    $v = LabelDriftVariants::for('Certification Fee - Latin Honors');

    expect($v['drop_prefix'])->toBe('Latin Honors')
        ->and($v['abbrev'])->toContain('Cert.')
        ->and($v)->toHaveKeys(['reorder', 'typo_del', 'typo_swap']);
});

test('an unprefixed label gets an added prefix and abbreviations expand to the short form', function () {
    $v = LabelDriftVariants::for('Informative Copy of Grades');

    expect($v['add_prefix'])->toBe('Certification Fee - Informative Copy of Grades')
        ->and($v['abbrev'])->toBe('Info. Copy of Grades');
});

test('variants are deterministic', function () {
    expect(LabelDriftVariants::for('Certified True Copy - Diploma'))
        ->toBe(LabelDriftVariants::for('Certified True Copy - Diploma'));
});
