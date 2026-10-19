<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * The template object store failed or returned something that does not match
 * what was saved (missing object, failed write, checksum mismatch). Distinct
 * from InvalidCertificateTemplateException: this is an infrastructure/integrity
 * problem, not something the caller can fix by changing their input.
 */
class TemplateStorageException extends RuntimeException
{
}
