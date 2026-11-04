<?php

namespace App\Http\Controllers;

use App\Http\Requests\Representative\RevokeRepresentativeRequest;
use App\Http\Requests\Representative\StoreRepresentativeRequest;
use App\Http\Resources\RequestRepresentativeResource;
use App\Models\DocumentRequest;
use App\Models\RequestRepresentative;
use App\Services\Representative\RequestRepresentativeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class RequestRepresentativeController extends Controller
{
    public function __construct(private RequestRepresentativeService $representatives) {}

    // GET /document-requests/{documentRequest}/representative
    public function show(Request $request, DocumentRequest $documentRequest): JsonResponse
    {
        $this->authorize('manageRepresentative', $documentRequest);

        $representative = RequestRepresentative::where('request_id', $documentRequest->request_id)
            ->orderByDesc('id')
            ->first();

        return response()->json([
            'data' => $representative ? new RequestRepresentativeResource($representative) : null,
        ]);
    }

    // POST /document-requests/{documentRequest}/representative   (multipart)
    public function store(StoreRepresentativeRequest $request, DocumentRequest $documentRequest): JsonResponse
    {
        $representative = $this->representatives->add(
            $documentRequest,
            $request->safe()->only(['full_name', 'email', 'relationship']),
            $request->proofFiles(),
            Auth::user(),
            $request,
        );

        return (new RequestRepresentativeResource($representative))
            ->response()
            ->setStatusCode(201);
    }

    // GET /representatives?status=pending   (Super Admin)
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewQueue', RequestRepresentative::class);

        $status = $request->query('status', RequestRepresentative::STATUS_PENDING);
        abort_unless(in_array($status, [
            RequestRepresentative::STATUS_PENDING,
            RequestRepresentative::STATUS_VERIFIED,
            RequestRepresentative::STATUS_REVOKED,
        ], true), 422, 'Unknown status.');

        $page = RequestRepresentative::where('status', $status)->orderBy('created_at')->paginate(25);

        return RequestRepresentativeResource::collection($page)->response();
    }

    // POST /representatives/{representative}/verify   (Super Admin)
    public function verify(Request $request, RequestRepresentative $representative): JsonResponse
    {
        $this->authorize('verify', $representative);

        $representative = $this->representatives->verify($representative, Auth::user(), $request);

        return (new RequestRepresentativeResource($representative))->response();
    }

    // POST /representatives/{representative}/revoke   (Super Admin)
    public function revoke(RevokeRepresentativeRequest $request, RequestRepresentative $representative): JsonResponse
    {
        $representative = $this->representatives->revoke(
            $representative,
            Auth::user(),
            $request->validated('reason'),
            $request,
        );

        return (new RequestRepresentativeResource($representative))->response();
    }

    // GET /representatives/{representative}/proofs/{kind}   (Super Admin, audited)
    public function proof(Request $request, RequestRepresentative $representative, string $kind): Response
    {
        $this->authorize('viewProof', $representative);
        abort_unless(in_array($kind, RequestRepresentative::REQUIRED_PROOFS, true), 404);

        $file = $this->representatives->proofContents($representative, $kind, Auth::user(), $request);

        return response($file['content'], 200, [
            'Content-Type'           => $file['mime'],
            'Content-Disposition'    => 'attachment; filename="' . $file['filename'] . '"',
            // An identity document must never be cached or sniffed.
            'Cache-Control'          => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
