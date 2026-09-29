<?php

namespace App\Http\Controllers;

use App\Http\Requests\DocumentRequest\IndexRequestItemsRequest;
use App\Http\Resources\DocumentRequestListResource;
use App\Models\DocumentRequest;
use App\Services\RequestItemListQuery;

/**
 * GET /document-requests/items
 *
 * The staff dashboard's one-row-per-document list. Each row is a document or
 * certificate with its own uuid, status and completion time, plus a lean
 * summary of its request (who, student number, requester type, progress
 * "items_completed of items_total"). Filtering, searching, ordering and
 * pagination happen in the database (see RequestItemListQuery).
 *
 * Privacy: only the plaintext identity columns of an undergrad profile are
 * loaded (same list as DocumentRequestController::UNDERGRAD_LIST_RELATION),
 * so the encrypted phone / address / date of birth / reason are never
 * fetched for a list.
 */
class RequestItemListController extends Controller
{
    private const REQUEST_RELATIONS = [
        'status',
        'studentProfile',
        'academicRecord',
        'alumniProfile',
        'alumniAcademicRecord',
        'undergradRequestorProfile:undergrad_requestor_profile_id,user_id,first_name,middle_name,last_name,suffix,student_number,program',
        'documents:request_document_id,request_id,status_id',
        'certificates:request_certificate_id,request_id,status_id',
    ];

    public function __construct(private RequestItemListQuery $listQuery) {}

    public function index(IndexRequestItemsRequest $request)
    {
        $page = $this->listQuery->build($request)->paginate($request->perPage());

        // One query for every request on this page (not one per row).
        $requests = DocumentRequest::withArchived()
            ->with(self::REQUEST_RELATIONS)
            ->whereIn('request_id', collect($page->items())->pluck('request_id')->unique()->all())
            ->get()
            ->keyBy('request_id');

        $page->through(function ($row) use ($requests) {
            $documentRequest = $requests->get($row->request_id);

            return [
                'type'             => $row->item_type,
                'id'               => (int) $row->item_id,
                'uuid'             => $row->item_uuid,
                'name'             => $row->item_name,
                'number_of_copies' => (int) $row->number_of_copies,
                'status_id'        => $row->status_id !== null ? (int) $row->status_id : null,
                'status'           => $row->status_name,
                'completed_at'     => $row->completed_at,
                'request'          => $documentRequest
                    ? (new DocumentRequestListResource($documentRequest))->summary()
                    : null,
            ];
        });

        return response()->json($page, 200);
    }
}
