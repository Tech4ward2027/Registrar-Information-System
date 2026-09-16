<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Undergrad Requestor Registration — Phase 3.
 *
 * Thrown by UserProvisioningService::provision() when an existing RIS
 * record is an Undergrad Requestor (role_id = ROLE_UNDERGRAD_REQUESTOR)
 * still sitting at status === 'Pending Verification' whose
 * undergrad_requestor_verifications row has not (yet) been decided
 * Approved — i.e. still genuinely Pending, not past its 14-day
 * abandonment window (that case instead self-heals to 'Expired' and
 * throws AccountExpiredException — see the widened QA #11 check in
 * provision()).
 *
 * Deliberately blocks login the same way AccountDeactivatedException/
 * AccountRejectedException/AccountExpiredException do — no Sanctum
 * token is ever issued for an account RIS hasn't decided is usable
 * yet — rather than letting the login "succeed" and relying on
 * EnsureAccountActive to reject every subsequent request. That would
 * mean issuing a real, cookie-bearing session to someone with no
 * actual RIS access, for no benefit: a single enforcement point (here,
 * before the token exists at all) is simpler, and it's exactly the
 * same lifecycle-blocking principle already established by the other
 * three exceptions in this family.
 *
 * One consequence worth being explicit about: because no token is ever
 * issued for a still-Pending Undergrad Requestor, EnsureAccountActive's
 * generic "This account is no longer active in RIS" message is never
 * actually reached for this status — there is no session for it to
 * intercept. The Phase 3 implementation plan asked to either confirm
 * that generic message is acceptable here or supply a role-aware,
 * friendlier one; this exception's own message (surfaced directly by
 * SsoCallbackController/AuthController, see their catch blocks) is that
 * friendlier message, delivered at the one point that actually matters.
 *
 * Distinct from AccountRejectedException specifically so the response
 * this produces reads as a calm status update ("still under review"),
 * never phrased or logged like an error/incident the way a rejection
 * or deactivation is.
 */
class AccountPendingVerificationException extends RuntimeException {}
