<?php

namespace App\Models;

use App\Enums\TicketPriorityEnum;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Support Ticketing — a ticket category (CAT-01 ... CAT-05).
 *
 * Priority belongs to the category, not to the requester or to individual
 * tickets. It is copied onto the ticket when the ticket is created.
 *
 * Categories are deactivated, never deleted: tickets keep referencing them.
 */
class TicketCategory extends Model
{
    use HasFactory;

    protected $table = 'ticket_categories';

    protected $fillable = [
        'code',
        'name',
        'description',
        'priority',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'priority'   => TicketPriorityEnum::class,
        'is_active'  => 'boolean',
        'sort_order' => 'integer',
    ];

    /**
     * The approved starter categories, from the Chat Support & Ticketing
     * policy §3.2. Read by TicketCategorySeeder (the one place they are
     * written) and by tests, so the list is spelled out exactly once.
     *
     * @var list<array{code:string,name:string,description:string,priority:string,sort_order:int}>
     */
    public const STARTER_CATEGORIES = [
        [
            'code'        => 'CAT-01',
            'name'        => 'Payment & Receipt (OR) Issues',
            'description' => 'Official Receipt validation failures, name mismatches, or 7-digit typographical errors.',
            'priority'    => 'high',
            'sort_order'  => 1,
        ],
        [
            'code'        => 'CAT-02',
            'name'        => 'Account & Access Clearance',
            'description' => 'GUISIS IIR submission blocks, PUPTAPS sync delays, or undergraduate onboarding approvals.',
            'priority'    => 'medium',
            'sort_order'  => 2,
        ],
        [
            'code'        => 'CAT-03',
            'name'        => 'Deficiency & Requirements',
            'description' => 'Questions on submitting missing credentials, clearances, or resolving Deficiency Notices.',
            'priority'    => 'medium',
            'sort_order'  => 3,
        ],
        [
            'code'        => 'CAT-04',
            'name'        => 'Releasing & Claiming Inquiries',
            'description' => 'Claim Ticket (QR/Code) recovery, 90-day validity status, or authorized representative rules.',
            'priority'    => 'low',
            'sort_order'  => 4,
        ],
        [
            'code'        => 'CAT-05',
            'name'        => 'General Inquiries & Portal Bugs',
            'description' => 'System errors, maintenance inquiries, or institutional program verifications.',
            'priority'    => 'low',
            'sort_order'  => 5,
        ],
    ];

    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class, 'category_id');
    }

    /**
     * Categories a requester may pick, in display order.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true)->orderBy('sort_order')->orderBy('id');
    }
}
