<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class CertificationType extends Model
{
    //certificate_type 
    protected $table = 'certificate_type';
    protected $primaryKey = 'certificate_type_id';
    protected $keyType = 'int';
    public $incrementing = true;
    public $timestamps = false;
    protected $fillable = [
        'certificate_name', 'certificate_requirements', 'certificate_process_period', 'access_id',
        'layout_header_left_url', 'layout_header_right_url', 'layout_footer_urls',
        'layout_header_logo_size', 'layout_footer_logo_size',
        'cashier_document_patterns', 'is_archived', 'archived_on', 'archived_by',
        'logbook_category_id', 'requires_source_submission', 'fulfillment_track_id',
        // FESPEC-0008 — see DocumentType's matching comment.
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

    // layout_footer_urls is stored as a JSON array of bare paths.
    // Do NOT add it to $casts here — the accessor below handles decoding
    // and URL resolution in one step.

    // -----------------------------------------------------------------------
    // Accessors — resolve storage paths → full URLs at read-time.
    //
    // Values in the DB can be either:
    //   (a) A full URL already (https://...) — stored by uploadLayoutLogo
    //       after the S3 migration; returned as-is.
    //   (b) A bare storage path (certification-layouts/1/header_left/file.png)
    //       — stored by older code or local-disk uploads; resolved via
    //       Storage::disk()->url() using the currently configured disk.
    //
    // This dual-mode handling ensures existing rows keep working after the
    // migration from local 'public' disk to S3.
    // -----------------------------------------------------------------------

    /**
     * Resolve a single stored value to a public URL.
     * Already-absolute URLs are passed through unchanged.
     */
    private function resolveStorageUrl(?string $value): ?string
    {
        if (!$value) {
            return null;
        }
        // Already a full URL (S3, CDN, or previous APP_URL-prefixed value).
        if (str_starts_with($value, 'http://') || str_starts_with($value, 'https://')) {
            return $value;
        }
        // Bare path — resolve through the currently configured disk.
        return Storage::disk(config('filesystems.default', 'public'))->url($value);
    }

    protected function layoutHeaderLeftUrl(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => $this->resolveStorageUrl($value),
        );
    }

    protected function layoutHeaderRightUrl(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => $this->resolveStorageUrl($value),
        );
    }

    /**
     * layout_footer_urls is a JSON array in the DB.
     * Each element can be a full URL or a bare path (see resolveStorageUrl).
     * On set: accept an array and JSON-encode it.
     */
    protected function layoutFooterUrls(): Attribute
    {
        return Attribute::make(
            get: function ($value) {
                $items = json_decode($value ?? '[]', true) ?? [];
                return array_values(array_filter(array_map(
                    fn ($item) => is_string($item) && trim($item) !== ''
                        ? $this->resolveStorageUrl($item)
                        : null,
                    $items
                )));
            },
            set: fn ($value) => json_encode(array_values($value ?? [])),
        );
    }

    // Was documentRequests() referencing document_request.cert_type_id, which
    // doesn't exist — certificate_type_id actually lives on request_certificate.
    // Confirmed unused anywhere in the app before renaming/fixing.
    public function requestCertificates()
    {
        return $this->hasMany(RequestCertificate::class, 'certificate_type_id');
    }

    public function accessType()
    {
        return $this->belongsTo(AccessType::class, 'access_id');
    }

    public function logbookCategory()
    {
        return $this->belongsTo(LogbookCategory::class, 'logbook_category_id');
    }

    // See DocumentType::fulfillmentTrack() — identical convention.
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
     * type's own name when no logbook_category is set (see
     * DocumentType::logbookLabel() — same convention on both tables).
     */
    public function logbookLabel(): string
    {
        return $this->logbookCategory?->name ?? $this->certificate_name;
    }

    /**
     * Number of non-archived requests currently using this certificate type
     * that are still in an active (non-terminal) status — Processing or
     * Ready to Claim. Backs the "N active requests are using this — can't
     * archive yet" guard from the Archive Policy — Document & Certificate
     * Management. DocumentRequest's own ExcludeArchivedScope excludes
     * already-archived requests automatically.
     */
    public function activeRequestsCount(): int
    {
        return $this->requestCertificates()
            ->whereHas('documentRequest', function ($query) {
                $query->whereHas(
                    'status',
                    fn ($status) => $status->whereIn('status_name', ['Processing', 'Ready to Claim'])
                );
            })
            ->count();
    }
}