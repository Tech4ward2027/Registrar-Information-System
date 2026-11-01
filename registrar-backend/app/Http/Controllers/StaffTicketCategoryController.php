<?php

namespace App\Http\Controllers;

use App\Http\Requests\Ticket\Admin\ManageTicketsRequest;
use App\Http\Requests\Ticket\Admin\StoreTicketCategoryRequest;
use App\Http\Requests\Ticket\Admin\UpdateTicketCategoryRequest;
use App\Http\Resources\StaffTicketCategoryResource;
use App\Models\TicketCategory;
use App\Services\Tickets\TicketAdminService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Support Ticketing — category management (tickets/Manage). Categories are
 * created and edited, never deleted. See TicketAdminService for the rules.
 */
class StaffTicketCategoryController extends Controller
{
    public function __construct(private readonly TicketAdminService $admin)
    {
    }

    // GET /admin/tickets/categories — every category, inactive included
    public function index(ManageTicketsRequest $request): AnonymousResourceCollection
    {
        return StaffTicketCategoryResource::collection(
            TicketCategory::query()->withCount('tickets')->orderBy('sort_order')->orderBy('id')->get()
        );
    }

    // POST /admin/tickets/categories
    public function store(StoreTicketCategoryRequest $request): JsonResponse
    {
        $category = $this->admin->createCategory($request, $request->user(), $request->validated());

        return (new StaffTicketCategoryResource($category))->response()->setStatusCode(201);
    }

    // PATCH /admin/tickets/categories/{category}
    public function update(UpdateTicketCategoryRequest $request, TicketCategory $category): StaffTicketCategoryResource
    {
        $updated = $this->admin->updateCategory($request, $request->user(), $category, $request->validated());

        return new StaffTicketCategoryResource($updated->loadCount('tickets'));
    }
}
