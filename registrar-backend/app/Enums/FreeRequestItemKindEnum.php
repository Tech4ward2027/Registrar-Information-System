<?php

namespace App\Enums;

/**
 * FESPEC-0008 — Free Document/Certificate Request.
 *
 * Discriminates which of the two type tables a free-request line item's
 * type_id refers to. This exists because — confirmed against the actual
 * schema, not assumed — Transcript of Records and Leave of Absence are
 * BOTH rows in `document_type` (ids 15 and 17), while Certificate of
 * Graduation is a row in the entirely separate `certificate_type` table
 * (id 6). An earlier draft of this feature treated all three as one
 * flat "document_type_id" concept; that would have silently queried the
 * wrong table for COG. Every FreeRequestEligibilityService /
 * FreeRequestService method that takes a type id also takes one of
 * these, mirroring the same 'documents' / 'certificates' split
 * StoreDocumentRequestRequest and DocumentRequestService already use
 * for a normal self-service filing — this feature reuses that shape
 * rather than inventing a new one.
 */
enum FreeRequestItemKindEnum: string
{
    case Document    = 'document';
    case Certificate = 'certificate';
}
