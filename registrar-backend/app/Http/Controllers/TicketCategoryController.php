<?php

namespace App\Http\Controllers;

use App\Http\Resources\TicketCategoryResource;
use App\Models\TicketCategory;

/**
 * Support Ticketing — the category list shown on the "new ticket" form and in
 * staff queue filters.
 *
 * Read-only and available to every signed-in account: categories are not
 * sensitive. Mounted under the 'feature:tickets' gate, so the whole module,
 * this endpoint included, answers 404 while the flag is off.
 */
class TicketCategoryController extends Controller
{
    public function index()
    {
        return TicketCategoryResource::collection(TicketCategory::query()->active()->get());
    }
}
