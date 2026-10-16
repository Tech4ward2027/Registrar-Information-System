<?php

/**
 * Feature flags — a deliberately small, explicit list.
 *
 * This codebase has no prior feature-flag infrastructure (confirmed: no
 * config('features...') calls anywhere before this file, no
 * FEATURE env precedent). Introducing one is a real architectural
 * decision, so it's kept minimal and documented here rather than
 * scattered across .env comments.
 *
 * SAFE-DEFAULT RULE: every flag in this file MUST default to `false`
 * (via the second argument to env()). A flag gating a real-money or
 * real-document-issuance feature must fail CLOSED if its env var is
 * ever missing — a fresh deploy, a rebuilt container, or a forgotten
 * .env line should never silently turn a gated feature ON. Do not flip
 * this default to `true` "to save a step" when enabling a flag in an
 * environment — set the env var explicitly instead.
 *
 * Each environment (local / staging / production) opts in explicitly by
 * setting the corresponding FEATURE_* variable in its own .env file.
 * This file is never edited per-environment — only .env is.
 */

return [

    /*
    |--------------------------------------------------------------------
    | FESPEC-0008 — Free Document/Certificate Request
    |--------------------------------------------------------------------
    |
    | Gates the admin Free Request page and its three backend endpoints
    | (search-accounts, eligibility, store) at the route layer, on top
    | of (not instead of) the existing 'free_requests' module/action
    | policy gate (see App\Models\Policy::MODULE_ACTIONS). The two are
    | independent and both must pass:
    |
    |   - This flag: "is the feature live in this environment at all."
    |     One switch, one place, flippable without a deployment via the
    |     environment's .env + `php artisan config:clear`.
    |   - The module/action gate: "does THIS staff account have View /
    |     File / Verify / Override on it." Per-account, configured via
    |     Policy Management, independent of this flag.
    |
    | A flag with no matching policy grant means nobody can use the
    | feature yet even when true. A false flag means nobody can use it
    | regardless of policy grants — useful for a full kill-switch during
    | staging validation or an incident, without touching every policy
    | row.
    |
    */
    'free_request_page' => (bool) env('FEATURE_FREE_REQUEST_PAGE', false),

    /*
    |--------------------------------------------------------------------
    | Cashier Reconciliation / System Health / AI label suggestions
    |--------------------------------------------------------------------
    |
    | Three independent kill-switches. Same fail-closed rule as above:
    | each defaults to false and is enabled per environment by setting
    | the FEATURE_* variable explicitly in that environment's .env.
    |
    |   - ai_label_suggestions: the LLM re-rank layer for unmatched
    |     cashier labels. Rule-based suggestions do NOT depend on this
    |     flag; only the outbound model call does. Off => no request
    |     ever leaves the backend for this feature.
    |   - system_health: rollups, anomaly detection, alerts and the
    |     System Health API surface.
    |   - analytics_ai_legacy: the retired analytics narrative card
    |     (POST analytics/ai-report) and chatbot (POST analytics/ai-query).
    |     Kept behind a flag, not deleted, until after the project
    |     defense. Off => both endpoints return 404 and the UI hides
    |     the cards.
    |
    | Which of these the SPA may learn about is controlled by
    | App\Support\FeatureFlags::CLIENT_VISIBLE (allow-list, so a flag
    | added here is never exposed to the browser by accident).
    |
    */
    'ai_label_suggestions' => (bool) env('FEATURE_AI_LABEL_SUGGESTIONS', false),
    'system_health'        => (bool) env('FEATURE_SYSTEM_HEALTH', false),
    'analytics_ai_legacy'  => (bool) env('FEATURE_ANALYTICS_AI_LEGACY', false),

];