<?php

use App\Exceptions\InvalidCertificateTemplateException;
use App\Exceptions\MissingMergeFieldsException;
use App\Services\Certificates\TemplateRenderer;
use App\Services\Certificates\TemplateSanitizer;

function ctrRenderer(): TemplateRenderer
{
    return app(TemplateRenderer::class);
}

test('backend values are substituted and HTML in a value can never become markup', function () {
    $html = ctrRenderer()->render('<p>{{ full_name }}</p>', ['full_name' => '<script>alert(1)</script> Juan']);

    expect($html)->toContain('&lt;script&gt;')
        ->and($html)->not->toContain('<script>');
});

test('controlled formatting filters work and nothing else is accepted', function () {
    $r = ctrRenderer();

    expect($r->render('<p>{{ full_name|upper }}</p>', ['full_name' => 'juan dela cruz']))->toBe('<p>JUAN DELA CRUZ</p>')
        ->and($r->render('<p>{{ full_name|lower }}</p>', ['full_name' => 'JUAN']))->toBe('<p>juan</p>')
        ->and($r->render('<p>{{ full_name|title }}</p>', ['full_name' => "JUAN o'brien-REYES III"]))->toBe("<p>Juan O'Brien-Reyes III</p>");

    $analysis = $r->analyze('<p>{{ full_name|system }}</p>');
    expect($analysis['errors'][0])->toContain('Unknown format');
});

test('an optional tag with no value prints the fill-in line', function () {
    $html = ctrRenderer()->render('<p>GWA: {{ gwa }}</p>', []);

    expect($html)->toBe('<p>GWA: __________</p>');
});

test('a missing REQUIRED field stops rendering and names the field', function () {
    try {
        ctrRenderer()->render('<p>{{ full_name }} {{ course }}</p>', ['full_name' => 'Juan'], ['full_name', 'course']);
        $this->fail('Expected MissingMergeFieldsException');
    } catch (MissingMergeFieldsException $e) {
        expect($e->fields)->toBe(['course']);
    }
});

test('computed tags derive from other values', function () {
    $html = ctrRenderer()->render(
        '<p>{{ issue_date_formal }} | {{ issue_date_ordinal }} | {{ batch_year }} | {{ salutation_name }} | {{ gwa_range }}</p>',
        ['issue_date' => '2026-03-28', 'date_graduated' => '2019', 'full_name' => 'Juan Santos Dela Cruz', 'latin_honors' => '(Magna Cum Laude)'],
    );

    expect($html)->toBe('<p>March 28, 2026 | 28th day of March 2026 | 2019 | Mr./Ms. Cruz | 1.1501 to 1.3500</p>');
});

test('a tag that is not in the registry is refused at render time too', function () {
    expect(fn () => ctrRenderer()->render('<p>{{ app_key }}</p>', ['app_key' => 'secret']))
        ->toThrow(InvalidCertificateTemplateException::class);
});

test('only whitelisted data is readable: an unregistered key in the data is never reachable', function () {
    $html = ctrRenderer()->render('<p>{{ full_name }}</p>', ['full_name' => 'Juan', 'password' => 'hunter2']);

    expect($html)->toBe('<p>Juan</p>');
});

test('values are stripped of control characters, collapsed and length-capped', function () {
    $html = ctrRenderer()->render('<p>{{ full_name }}</p>', ['full_name' => "Juan\x00\x07   \n  Cruz"]);
    expect($html)->toBe('<p>Juan Cruz</p>');

    $long = ctrRenderer()->render('<p>{{ full_name }}</p>', ['full_name' => str_repeat('a', 5000)]);
    expect(mb_strlen(strip_tags($long)))->toBe(500);
});

test('the sanitizer is idempotent and keeps allowed layout', function () {
    $s = app(TemplateSanitizer::class);
    $in = '<div style="text-align:center;font-size:14pt"><h2>CERTIFICATION</h2><table><tr><td colspan="2">{{ full_name }}</td></tr></table></div>';

    $once = $s->sanitize($in);

    expect($once)->toContain('<h2>CERTIFICATION</h2>')
        ->and($once)->toContain('<td colspan="2">')
        ->and($once)->toContain('text-align')
        ->and($s->sanitize($once))->toBe($once);
});

test('the sanitizer drops event handlers, script, images, links and url() styles', function () {
    $out = app(TemplateSanitizer::class)->sanitize(
        '<p onmouseover="x()" style="background:url(http://e/x);color:red">Hi</p><a href="javascript:alert(1)">l</a><img src=x onerror=y><script>z</script>'
    );

    expect($out)->not->toContain('onmouseover')
        ->and($out)->not->toContain('url(')
        ->and($out)->not->toContain('<a')
        ->and($out)->not->toContain('<img')
        ->and($out)->not->toContain('script')
        ->and($out)->toContain('Hi');
});
