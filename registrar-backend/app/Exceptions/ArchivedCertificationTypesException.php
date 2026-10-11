<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown by CertificationLayoutService::bulkUpdate() when any targeted
 * certification type is archived (its template is read-only). Carrying the
 * ids lets the controller tell the caller exactly which ones to restore or
 * drop; throwing from inside the transaction is what rolls the batch back.
 */
class ArchivedCertificationTypesException extends RuntimeException
{
    /** @param int[] $certificateTypeIds */
    public function __construct(public readonly array $certificateTypeIds)
    {
        parent::__construct('One or more certificates are archived and their templates are read-only.');
    }
}
