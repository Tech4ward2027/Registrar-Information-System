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
];
