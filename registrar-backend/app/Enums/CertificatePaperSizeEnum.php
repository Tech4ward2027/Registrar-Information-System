<?php

namespace App\Enums;

/** Paper sizes a certificate template may declare (dompdf-compatible names in Phase 3). */
enum CertificatePaperSizeEnum: string
{
    case A4     = 'A4';
    case Letter = 'LETTER';
    case Legal  = 'LEGAL';
    case Folio  = 'FOLIO';
}
