<?php

/*
|--------------------------------------------------------------------------
| Database-driven certificate templates (Phase 2)
|--------------------------------------------------------------------------
| Template BODIES live in object storage (private S3 bucket in production);
| MySQL keeps the metadata and the storage path. Generated certificate PDFs
| are never stored here — see CertificateTemplateService.
|
| 'disk' follows FILESYSTEM_DISK unless CERTIFICATE_TEMPLATE_DISK overrides
| it, so production (s3) and staging/local (local disk) need no code change.
| No new IAM permission is required: the app user only needs
| s3:GetObject / s3:PutObject / s3:ListBucket on the existing bucket.
*/
return [

    'disk' => env('CERTIFICATE_TEMPLATE_DISK', env('FILESYSTEM_DISK', 'local')),

    // Key prefix inside the bucket: certificate-templates/{slug}/template-v{n}.html
    'prefix' => 'certificate-templates',

    // Hard cap on a template body, in characters.
    'max_content_length' => 200_000,

    // Margins are millimetres. Bounds stop a template from being unprintable.
    'margin_min_mm' => 5,
    'margin_max_mm' => 60,

    'defaults' => [
        'paper_size'  => 'A4',
        'orientation' => 'portrait',
        'margin_mm'   => 25.4,
    ],

    // Rendered in place of an OPTIONAL merge tag that has no value, so the
    // printed certificate shows a fill-in line (same as the legacy fillOrLine).
    'blank_placeholder' => '__________',

    // Template bodies are immutable per version, so the cache key (id + sha256)
    // never needs invalidating. Seconds; 0 disables.
    'cache_ttl' => 300,

    // ── Server-side generation (Phase 3) ────────────────────────────────────

    // Control number: {prefix}-{year}-{sequence padded to `pad`}, e.g.
    // PUP-CERT-2026-0042. The sequence restarts every calendar year (in the
    // display timezone) and keeps growing past the padding (10000, 10001...).
    'control_number' => [
        'prefix' => env('CERTIFICATE_CONTROL_PREFIX', 'PUP-CERT'),
        'pad'    => 4,
    ],

    // Keep the merge values used for each certificate on its generation
    // record, so "what exactly was printed" can be answered later (the
    // Registrar-entered fields are not recoverable from anywhere else). The
    // snapshot is personal data: set to false to store none, and include the
    // table in the data-retention/disposal schedule.
    'store_payload_snapshot' => (bool) env('CERTIFICATE_STORE_PAYLOAD_SNAPSHOT', true),

    'pdf' => [
        // Bundled with dompdf, full Unicode coverage (Filipino names with ñ etc.).
        'font'          => 'DejaVu Serif',
        'font_size_pt'  => 12,
        // dompdf scratch + font-cache directory (must be writable by the app).
        'temp_dir'      => storage_path('framework/cache/dompdf'),
        // Header/footer logos are read from the app's own disk and embedded;
        // anything larger is skipped rather than slowing every certificate.
        'max_logo_bytes' => 3_000_000,
    ],

];