<?php

namespace App\Enums;

/**
 * Lifecycle of a certificate generation attempt.
 *
 *   Pending   — control number reserved, PDF not built yet
 *   Generated — PDF built and returned to the caller
 *   Failed    — the attempt did not produce a PDF (number stays reserved)
 *
 * Pending is the only state that may change; Generated and Failed are final.
 */
enum CertificateGenerationStatusEnum: string
{
    case Pending   = 'pending';
    case Generated = 'generated';
    case Failed    = 'failed';
}
