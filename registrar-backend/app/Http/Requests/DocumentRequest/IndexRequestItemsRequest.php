<?php

namespace App\Http\Requests\DocumentRequest;

/**
 * GET /document-requests/items — the per-document dashboard list.
 *
 * Same parameters, allow-lists and limits as the per-request list
 * (search, status, classification, document, sort, view, page, per_page,
 * all_statuses), so the frontend keeps ONE set of query params. Here
 * `status` and `document` apply to the ITEM's own status and name. The
 * only difference is audience: staff only, since a requester has no use
 * for a cross-request list.
 */
class IndexRequestItemsRequest extends IndexDocumentRequestsRequest
{
    public function authorize(): bool
    {
        return parent::authorize() && $this->user()->isStaff();
    }
}
