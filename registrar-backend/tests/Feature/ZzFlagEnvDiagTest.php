<?php

// TEMPORARY diagnostic — delete after use. Prints where each layer reads from.
test('diagnose FEATURE_ANALYTICS_AI_LEGACY sources', function () {
    $n = 'FEATURE_ANALYTICS_AI_LEGACY';

    fwrite(STDERR, "\n--- {$n} ---\n");
    fwrite(STDERR, 'getenv():            ' . var_export(getenv($n), true) . "\n");
    fwrite(STDERR, '$_ENV:               ' . var_export($_ENV[$n] ?? '(unset)', true) . "\n");
    fwrite(STDERR, '$_SERVER:            ' . var_export($_SERVER[$n] ?? '(unset)', true) . "\n");
    fwrite(STDERR, 'env():               ' . var_export(env($n, '(unset)'), true) . "\n");
    fwrite(STDERR, 'config():            ' . var_export(config('features.analytics_ai_legacy'), true) . "\n");
    fwrite(STDERR, 'APP_ENV:             ' . var_export(app()->environment(), true) . "\n");
    fwrite(STDERR, 'config cached?:      ' . var_export(app()->configurationIsCached(), true) . "\n");
    fwrite(STDERR, 'features.php path:   ' . config_path('features.php') . "\n");
    fwrite(STDERR, 'phpunit.xml loaded:  ' . var_export($_SERVER['argv'] ?? [], true) . "\n");

    expect(true)->toBeTrue();
});
