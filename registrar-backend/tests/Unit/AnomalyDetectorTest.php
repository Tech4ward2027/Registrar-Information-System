<?php

use App\Support\Health\AnomalyDetector;

/*
 * Pure unit tests — no framework boot, no database. The "normal" baseline is
 * a 7-day pattern repeated four times (28 days): median 3, MAD 1.
 */

function p3Normal(int $days = 28): array
{
    $pattern = [3, 4, 2, 5, 3, 4, 3];

    return array_map(fn ($i) => $pattern[$i % 7], range(0, $days - 1));
}

function p3Detector(): AnomalyDetector
{
    // Explicit values so the test never depends on env/config.
    return new AnomalyDetector(
        zThreshold: 3.5, minCount: 5, minBaselineDays: 7, criticalZ: 7.0,
        flatCriticalCount: 25, rateMinAttempts: 10, rateMinDelta: 0.15,
        rateMinBaselineDays: 5, rateCriticalRate: 0.5,
    );
}

test('median and MAD handle odd and even lengths', function () {
    expect(AnomalyDetector::median([5, 1, 3]))->toBe(3.0);
    expect(AnomalyDetector::median([4, 1, 3, 2]))->toBe(2.5);
    expect(AnomalyDetector::mad(p3Normal(), 3.0))->toBe(1.0);
});

test('a normal day inside the usual range is not anomalous', function () {
    foreach ([2, 3, 4, 5] as $observed) {
        expect(p3Detector()->detectCount($observed, p3Normal())->anomalous)->toBeFalse();
    }
});

test('a clear spike is anomalous and critical', function () {
    $d = p3Detector()->detectCount(30, p3Normal());

    expect($d->anomalous)->toBeTrue()
        ->and($d->reason)->toBe('modified_z')
        ->and($d->severity)->toBe('critical')
        ->and($d->median)->toBe(3.0)
        ->and($d->score)->toBeGreaterThan(7.0);
});

test('a moderate spike is a warning, not critical', function () {
    // score = 0.6745 * (9 - 3) / 1 = 4.05
    $d = p3Detector()->detectCount(9, p3Normal());

    expect($d->anomalous)->toBeTrue()->and($d->severity)->toBe('warning');
});

test('a zero-filled baseline (MAD = 0) falls back to the floor rule instead of dividing by zero', function () {
    $zeros = array_fill(0, 28, 0);

    expect(p3Detector()->detectCount(4, $zeros)->anomalous)->toBeFalse();   // below floor
    expect(p3Detector()->detectCount(5, $zeros)->reason)->toBe('flat_baseline');
    expect(p3Detector()->detectCount(8, $zeros)->severity)->toBe('warning');
    expect(p3Detector()->detectCount(30, $zeros)->severity)->toBe('critical');
});

test('a flat non-zero baseline needs both the floor margin and a doubling', function () {
    $flat = array_fill(0, 28, 5);

    expect(p3Detector()->detectCount(9, $flat)->anomalous)->toBeFalse();
    expect(p3Detector()->detectCount(10, $flat)->anomalous)->toBeTrue();
});

test('tiny volumes never alert, even when proportionally huge', function () {
    $d = p3Detector()->detectCount(3, array_fill(0, 28, 0));

    expect($d->anomalous)->toBeFalse()->and($d->reason)->toBe('below_floor');
});

test('too little history never alerts', function () {
    $d = p3Detector()->detectCount(100, [3, 4, 2]);

    expect($d->anomalous)->toBeFalse()->and($d->reason)->toBe('insufficient_baseline');
});

test('a single past outage in the baseline does not mask the next incident', function () {
    $baseline     = p3Normal();
    $baseline[3]  = 200;   // one old outage day

    expect(p3Detector()->detectCount(6, $baseline)->anomalous)->toBeFalse();   // ordinary day stays quiet
    expect(p3Detector()->detectCount(12, $baseline)->anomalous)->toBeTrue();   // real spike still caught
});

test('a decrease is never anomalous', function () {
    $high = array_fill(0, 28, 50);

    expect(p3Detector()->detectCount(5, $high)->anomalous)->toBeFalse();
});

// ── rate ─────────────────────────────────────────────────────────────────────

function p3RateBaseline(): array
{
    // 20 attempts/day; 2 or 3 failures -> 10-15 %. Median 12.5 %, MAD 2.5 pts.
    return array_map(fn ($i) => [$i % 2 === 0 ? 2 : 3, 20], range(0, 27));
}

test('a jump in failure rate is anomalous', function () {
    $d = p3Detector()->detectRate(15, 30, p3RateBaseline());

    expect($d->anomalous)->toBeTrue()
        ->and($d->reason)->toBe('modified_z_rate')
        ->and($d->severity)->toBe('critical');   // 50 %
});

test('a rate with too few attempts is ignored', function () {
    expect(p3Detector()->detectRate(4, 4, p3RateBaseline())->reason)->toBe('below_min_attempts');
});

test('a small rate increase below the absolute margin is ignored', function () {
    // 6/30 = 20 %, only 7.5 points above the 12.5 % median.
    expect(p3Detector()->detectRate(6, 30, p3RateBaseline())->anomalous)->toBeFalse();
});

test('baseline days with too few attempts do not count toward the rate baseline', function () {
    $thin = array_map(fn () => [1, 3], range(0, 27));   // 3 attempts/day < 10

    expect(p3Detector()->detectRate(15, 30, $thin)->reason)->toBe('insufficient_baseline');
});

test('a perfectly flat rate baseline uses the absolute margin', function () {
    $flat = array_fill(0, 28, [2, 20]);   // always 10 %

    expect(p3Detector()->detectRate(12, 30, $flat)->reason)->toBe('flat_baseline_rate');
});
