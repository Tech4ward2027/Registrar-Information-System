<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Undergrad Requestor Registration — Phase 3 (D8).
 *
 * Thrown by UserProvisioningService::provision() when an existing RIS
 * record has status === 'Rejected' — set exclusively by the Admin
 * reject action on an Undergrad Requestor's verification (Phase 4),
 * which writes this same value to both undergrad_requestor_
 * verifications.status and users.status in the same transaction (see
 * that migration's docblock), so checking the users.status column
 * alone here is sufficient — no extra join back to
 * undergrad_requestor_verifications is needed to know this.
 *
 * Handled identically to AccountDeactivatedException everywhere it
 * matters: blocks login before a Sanctum token is ever issued, and
 * triggers the same IdP-token revocation
 * (SsoAuthService::revokeOnRejection()) so a rejected person's
 * "Back to Login" flow doesn't loop on a still-valid IdP session — see
 * that method's docblock for why the revoke exists at all.
 *
 * Kept as its own class rather than reusing AccountDeactivatedException
 * — same reasoning that class's own docblock gives for not reusing
 * UnregisteredAccountException: distinct rejection reasons stay
 * distinguishable by callers/logs without parsing message strings,
 * and the two ARE meaningfully different events ("an Admin explicitly
 * declined this specific verification decision" vs. "RIS cut off an
 * account that used to work").
 */
class AccountRejectedException extends RuntimeException {}
