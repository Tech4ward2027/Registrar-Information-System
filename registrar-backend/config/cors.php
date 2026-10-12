<?php
return [
    'paths' => ['api/*', 'sanctum/csrf-cookie'],
    'allowed_methods' => ['*'],
    'allowed_origins' => [
        'http://localhost',
        'https://localhost',
        'http://localhost:5173', 
        'https://d38uplsfjqa5i6.cloudfront.net',
        'https://registrar-information-system-bsit2027.com',              
        'https://pupt-ris.registrar-information-system-bsit2027.com',
    ],
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['*'],
    // Lets the browser read the certificate headers from the PDF response
    // (CertificateGenerationController): the control number to display and the
    // template version / generation id for support.
    'exposed_headers' => ['X-Control-Number', 'X-Template-Version', 'X-Generation-Id', 'Content-Disposition'],
    'max_age' => 0,
    'supports_credentials' => true,
];