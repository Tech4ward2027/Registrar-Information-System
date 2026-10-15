<?php

use App\Support\ProcessPeriodParser;

/* Pure unit test — no framework state needed. A working day is 720 here
 * (the default calendar's 08:00-20:00). */

test('parses the formats used in the document catalog', function (string $label, int $expected) {
    expect(ProcessPeriodParser::toMinutes($label, 720))->toBe($expected);
})->with([
    'minutes only'            => ['50 minute/s', 50],
    'days and minutes'        => ['2 working day/s, 23 minute/s', 2 * 720 + 23],
    'days hours minutes'      => ['3 working days, 3 hours, 42 minutes', 3 * 720 + 3 * 60 + 42],
    'singular slash forms'    => ['1 working day/s, 5 hour/s, 30 minute/s', 720 + 5 * 60 + 30],
    'abbreviations'           => ['1 hr 15 mins', 75],
    'upper case'              => ['2 HOURS', 120],
]);

test('returns null when there is nothing to read', function (?string $label) {
    expect(ProcessPeriodParser::toMinutes($label, 720))->toBeNull();
})->with([
    'null'                 => [null],
    'empty'                => [''],
    'whitespace'           => ['   '],
    'placeholder text'     => ['Pending admin configuration - set via Document Management'],
    'zero'                 => ['0 minutes'],
]);

test('a non-positive working day length yields no target', function () {
    expect(ProcessPeriodParser::toMinutes('1 working day', 0))->toBeNull();
});
