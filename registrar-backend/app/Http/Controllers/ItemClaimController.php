<?php

namespace App\Http\Controllers;

use App\Http\Requests\DocumentRequest\ClaimDocumentRequestRequest;
use App\Http\Requests\DocumentRequest\ConfirmItemClaimRequest;
use App\Services\ItemClaimService;

/**
 * Per-item claiming, two steps (see ItemClaimService for the rules):
 *
 *   POST /document-requests/claim/lookup    read-only: what does this code cover?
 *   POST /document-requests/claim/confirm   release the documents staff selected
 *
 * Authorization is the 'claim' policy (staff + dashboard Complete) via the
 * FormRequests, plus the route middleware; the service re-checks Complete.
 * The existing POST /document-requests/claim (whole request) is untouched.
 */
class ItemClaimController extends Controller
{
    public function __construct(private ItemClaimService $claims) {}

    public function lookup(ClaimDocumentRequestRequest $request)
    {
        return response()->json($this->claims->lookup($request->validated()), 200);
    }

    public function confirm(ConfirmItemClaimRequest $request)
    {
        return response()->json(
            $this->claims->confirm($request->credential(), $request->itemUuids()),
            200
        );
    }
}
