<?php

namespace App\Enums;

/**
 * FESPEC-0008 — Free Document/Certificate Request.
 *
 * Single source of truth for document_request.channel — how a request
 * entered the system. Mirrors the convention already established by
 * RequestStatusEnum: a type-safe enum backing a plain string DB column
 * (see 2026_09_04_000001_add_channel_to_document_request.php's docblock
 * for why this is a string column rather than a DB enum type).
 *
 * Referenced everywhere a channel needs to be read or written —
 * DocumentRequestService::createRequest() (defaults new rows to
 * SelfService), FreeRequestService::createFreeRequest() (writes
 * AdminFiledFree), FreeRequestEligibilityService (filters by
 * AdminFiledFree when checking prior free issuances) — so there is
 * exactly one place a valid channel value is ever spelled out.
 */
enum RequestChannelEnum: string
{
    /**
     * The requestor filed this themselves via the Student/Alumni Request
     * page (DocumentRequestController::store(), role:1,2). The default
     * for every request ever created before this feature existed, and
     * the default for every new request unless explicitly filed as
     * AdminFiledFree.
     */
    case SelfService = 'self_service';

    /**
     * Filed BY a Registrar Admin ON BEHALF OF the requestor via the Free
     * Request page, per the Free Documents/Certificates Request Policy
     * §3.2. Never carries an or_number — free requests are not tied to a
     * Cashier transaction.
     */
    case AdminFiledFree = 'admin_filed_free';
}
