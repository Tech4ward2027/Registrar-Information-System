<?php

namespace App\Exceptions;

use RuntimeException;

/** A template body or its settings failed validation (unknown tag, malformed tag, ...). */
class InvalidCertificateTemplateException extends RuntimeException
{
    /** @param array<string, list<string>> $errors field => messages (shaped like Laravel validation errors) */
    public function __construct(public readonly array $errors)
    {
        parent::__construct('The certificate template is invalid.');
    }
}
