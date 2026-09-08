<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DocumentType extends Model
{
    protected $table = 'document_type';
    protected $primaryKey = 'document_type_id';
    public $timestamps = false;
    protected $fillable = [
        'document_name', 'document_description', 'document_requirements', 'document_process_period',
        'access_id', 'cashier_document_patterns', 'is_archived', 'archived_on', 'archived_by',
        'logbook_category_id', 'requires_source_submission', 'fulfillment_track_id',
        // FESPEC-0008 — Free Document/Certificate Request (Phase 1 columns,
        // wired into the model in Phase 2 since FreeRequestEligibilityService
        // reads them directly and a future type-management screen needs to
        // set them via mass assignment, same as requires_source_submission
        // above).
        'is_free_eligible', 'free_issuance_limit',
    ];

    protected $casts = [
        'cashier_document_patterns'  => 'array',
        'is_archived'                => 'boolean',
        'archived_on'                => 'datetime',
        'requires_source_submission' => 'boolean',
        'is_free_eligible'           => 'boolean',
        'free_issuance_limit'        => 'integer',
    ];

    public function requestDocuments()
    {
        return $this->hasMany(RequestDocument::class, 'document_type_id');
    }

    public function accessType()
    {
        return $this->belongsTo(AccessType::class, 'access_id');
    }

    public function logbookCategory()
    {
        return $this->belongsTo(LogbookCategory::class, 'logbook_category_id');
    }

    // fulfillment_track_id: added by migration 2026_08_29_000008_add_
    // fulfillment_tracks_and_release_groups — see RequestReleaseGroupService,
    // which groups a request's line items into separate claim tickets when
    // they span more than one track. NULL = the implicit "standard" track.
    public function fulfillmentTrack()
    {
        return $this->belongsTo(FulfillmentTrack::class, 'fulfillment_track_id');
    }

    // Named archivedByUser() (not archivedBy()) so it serializes to
    // "archived_by_user" — "archived_by" is the raw FK column, same
    // convention as DocumentRequest::archivedByUser().
    public function archivedByUser()
    {
        return $this->belongsTo(SystemUser::class, 'archived_by', 'user_id');
    }

    /**
     * The label this type should be logged under. Falls back to the
     * type's own name when no logbook_category is set — most types don't
     * collapse with anything else, so most rows won't set one (see
     * logbook_category migration docblock). Only types that genuinely
     * roll up into a shared umbrella line (e.g. every "Certified True
     * Copy of X" variant) need logbook_category_id assigned.
     */
    public function logbookLabel(): string
    {
        return $this->logbookCategory?->name ?? $this->document_name;
    }

    /**
     * Number of non-archived requests currently using this document type
     * that are still in an active (non-terminal) status — i.e. Processing
     * or Ready to Claim. Completed/Forfeited/Cancelled requests, and
     * already-archived requests, don't block archiving.
     *
     * Backs the "3 active requests are using this — can't archive yet"
     * guard from the Archive Policy — Document & Certificate Management.
     * DocumentRequest carries its own ExcludeArchivedScope global scope,
     * so archived requests are excluded here automatically.
     */
    public function activeRequestsCount(): int
    {
        return $this->requestDocuments()
            ->whereHas('documentRequest', function ($query) {
                $query->whereHas(
                    'status',
                    fn ($status) => $status->whereIn('status_name', ['Processing', 'Ready to Claim'])
                );
            })
            ->count();
    }
}