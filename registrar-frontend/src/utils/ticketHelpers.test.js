import test from "node:test";
import assert from "node:assert/strict";
import {
  isTicketsFeatureEnabled,
  getRoleTicketRoot,
  parseTicketApiError,
  formatEventLabel,
} from "./ticketHelpers.js";
import { MODULE_KEYS, MODULE_ACTIONS, hasModuleAccess, hasModuleAction } from "./policy.js";

test("isTicketsFeatureEnabled fails closed unless features.tickets is strictly true", () => {
  assert.equal(isTicketsFeatureEnabled(null), false);
  assert.equal(isTicketsFeatureEnabled({}), false);
  assert.equal(isTicketsFeatureEnabled({ features: {} }), false);
  assert.equal(isTicketsFeatureEnabled({ features: { tickets: false } }), false);
  assert.equal(isTicketsFeatureEnabled({ features: { tickets: "true" } }), false);
  assert.equal(isTicketsFeatureEnabled({ features: { tickets: true } }), true);
});

test("getRoleTicketRoot maps each RIS role to its ticket workspace path", () => {
  assert.equal(getRoleTicketRoot("student"), "/student/tickets");
  assert.equal(getRoleTicketRoot("alumni"), "/alumni/tickets");
  assert.equal(getRoleTicketRoot("undergrad_requestor"), "/undergrad/tickets");
  assert.equal(getRoleTicketRoot("admin"), "/staff/tickets");
  assert.equal(getRoleTicketRoot("super_admin"), "/super-admin/tickets");
  assert.equal(getRoleTicketRoot("unknown"), null);
});

test("policy helpers expose all five granular tickets actions", () => {
  assert.equal(MODULE_KEYS.TICKETS, "tickets");
  assert.deepEqual(MODULE_ACTIONS.tickets, ["View", "Claim", "Reply", "Resolve", "Manage"]);

  const staffClaimer = {
    role_name: "admin",
    features: { tickets: true },
    effective_permissions: { tickets: ["View", "Claim", "Reply"] },
  };
  assert.equal(hasModuleAccess(staffClaimer, "tickets"), true);
  assert.equal(hasModuleAction(staffClaimer, "tickets", "Claim"), true);
  assert.equal(hasModuleAction(staffClaimer, "tickets", "Resolve"), false);
  assert.equal(hasModuleAction(staffClaimer, "tickets", "Manage"), false);

  const superAdmin = {
    role_name: "super_admin",
    features: { tickets: true },
    effective_permissions: {},
  };
  assert.equal(hasModuleAction(superAdmin, "tickets", "Manage"), true);

  const student = { role_name: "student", effective_permissions: {} };
  assert.equal(hasModuleAction(student, "tickets", "Manage"), false);
});

test("parseTicketApiError surfaces 409 already_claimed with claimedBy details", async () => {
  const err = {
    response: {
      status: 409,
      data: {
        code: "already_claimed",
        message: "Already claimed.",
        claimed_by: { id: 12, name: "Juan Dela Cruz" },
      },
    },
  };
  const parsed = await parseTicketApiError(err);
  assert.equal(parsed.status, 409);
  assert.equal(parsed.code, "already_claimed");
  assert.deepEqual(parsed.claimedBy, { id: 12, name: "Juan Dela Cruz" });
  assert.match(parsed.message, /Juan Dela Cruz/);
});

test("parseTicketApiError surfaces 429 Retry-After seconds and Blob 422 JSON errors", async () => {
  const rateLimitErr = {
    rateLimited: true,
    retryAfterSeconds: 42,
    response: { status: 429, data: { message: "Slow down." } },
  };
  const parsed429 = await parseTicketApiError(rateLimitErr);
  assert.equal(parsed429.status, 429);
  assert.equal(parsed429.retryAfterSeconds, 42);
  assert.match(parsed429.message, /42 seconds/);

  const blobPayload = new Blob(
    [JSON.stringify({ message: "Too many rows", errors: { to: ["Range exceeds 100,000 events."] } })],
    { type: "application/json" }
  );
  const blobErr = { response: { status: 422, data: blobPayload } };
  const parsed422 = await parseTicketApiError(blobErr);
  assert.equal(parsed422.status, 422);
  assert.equal(parsed422.message, "Range exceeds 100,000 events.");
  assert.deepEqual(parsed422.fieldErrors.to, ["Range exceeds 100,000 events."]);
});

test("formatEventLabel translates known audit event types cleanly", () => {
  assert.equal(formatEventLabel("claimed"), "Claimed from queue");
  assert.equal(formatEventLabel("sla_first_response_breached"), "First-response SLA breached");
});
