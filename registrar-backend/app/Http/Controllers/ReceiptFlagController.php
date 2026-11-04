<?php

namespace App\Http\Controllers;

use App\Http\Requests\ReceiptFlag\FlagReceiptRequest;
use App\Models\DocumentRequest;
use App\Services\ReceiptFlagService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class ReceiptFlagController extends Controller
{
    public function __construct(private ReceiptFlagService $receiptFlagService) {}

    // POST /document-requests/{documentRequest}/flag-receipt
    public function store(FlagReceiptRequest $request, DocumentRequest $documentRequest): JsonResponse
    {
        $notified = $this->receiptFlagService->flag(
            $documentRequest,
            Auth::user(),
            $request->validated('reason'),
            $request,
        );

        return response()->json([
            'flagged'  => true,
            'notified' => $notified,
        ], 200);
    }
}
