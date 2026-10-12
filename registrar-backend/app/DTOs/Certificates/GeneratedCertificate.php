<?php

namespace App\DTOs\Certificates;

/**
 * Result of a successful generation. `pdf` lives in memory only: it is
 * returned to the caller and never persisted anywhere.
 */
final readonly class GeneratedCertificate
{
    public function __construct(
        public string $pdf,
        public string $controlNumber,
        public int $templateVersion,
        public int $generationId,
    ) {}

    public function filename(): string
    {
        return $this->controlNumber . '.pdf';
    }
}
