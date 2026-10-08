<?php

use App\Support\StringSimilarity;

test('identical strings score 1 and empty-vs-nonempty scores 0', function () {
    expect(StringSimilarity::jaroWinkler('diploma', 'diploma'))->toBe(1.0)
        ->and(StringSimilarity::jaroWinkler('', ''))->toBe(1.0)
        ->and(StringSimilarity::jaroWinkler('abc', ''))->toBe(0.0);
});

test('jaro-winkler matches the classic MARTHA / MARHTA reference value', function () {
    expect(StringSimilarity::jaroWinkler('martha', 'marhta'))->toEqualWithDelta(0.9611, 0.0005);
});

test('multibyte characters are compared as characters, not bytes', function () {
    expect(StringSimilarity::jaroWinkler('niño', 'niño'))->toBe(1.0)
        ->and(StringSimilarity::jaroWinkler('niño', 'nino'))->toBeGreaterThan(0.85);
});

test('token overlap is order-insensitive and tolerates a typo', function () {
    expect(StringSimilarity::tokenOverlap(['copy', 'grades'], ['grades', 'copy']))->toBe(1.0)
        ->and(StringSimilarity::tokenOverlap(['informative', 'grades'], ['informatve', 'grades']))->toBeGreaterThan(0.9)
        ->and(StringSimilarity::tokenOverlap(['diploma'], ['grades']))->toBe(0.0)
        ->and(StringSimilarity::tokenOverlap([], ['x']))->toBe(0.0);
});
