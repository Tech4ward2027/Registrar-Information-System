<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A certificate could not be generated for a reason the caller should hear
 * about. Carries the HTTP status and a stable machine-readable `errorCode` so
 * the frontend can branch without parsing the message.
 *
 * Messages are written for the Registrar and never include template bodies,
 * storage keys or exception internals.
 */
class CertificateGenerationException extends RuntimeException
{
    /** @param array<string, mixed> $context extra fields merged into the JSON response */
    private function __construct(
        string $message,
        public readonly int $status,
        public readonly string $errorCode,
        public readonly array $context = [],
    ) {
        parent::__construct($message);
    }

    /** No active database template: the client keeps using its legacy CERT_CONFIG. */
    public static function templateNotConfigured(): self
    {
        return new self(
            'No active template is configured for this certificate type. Use the legacy certificate layout.',
            409,
            'template_not_configured',
            ['source' => 'legacy'],
        );
    }

    public static function certificateTypeNotFound(): self
    {
        return new self('Certificate type not found.', 404, 'certificate_type_not_found');
    }

    public static function studentNotFound(): self
    {
        return new self('No student or requester record was found for that student number.', 404, 'student_not_found');
    }

    public static function requestNotFound(): self
    {
        return new self('Request not found.', 404, 'request_not_found');
    }

    public static function requestMismatch(string $message, string $errorCode): self
    {
        return new self($message, 422, $errorCode);
    }

    /** The stored template body is no longer valid; the Registrar cannot fix this by retrying. */
    public static function templateInvalid(): self
    {
        return new self(
            'The certificate template is invalid and could not be rendered. Please contact an administrator.',
            422,
            'template_invalid',
        );
    }

    public static function renderFailed(): self
    {
        return new self(
            'The certificate could not be generated. No certificate was issued and the request was not changed.',
            500,
            'generation_failed',
        );
    }
}
