<?php

namespace App\Http\Controllers;

use App\Http\Requests\CertificationType\ArchiveCertificationTypeRequest;
use App\Http\Requests\CertificationType\StoreCertificationTypeRequest;
use App\Http\Requests\CertificationType\UpdateCertificationLayoutRequest;
use App\Http\Requests\CertificationType\UpdateCertificationTypeRequest;
use App\Http\Requests\CertificationType\UploadCertificationLayoutLogoRequest;
use App\Models\AuditLog;
use App\Models\CertificationType;
use App\Models\SystemUser;
use App\Services\AuditLogger;
use App\Services\CashierPatternSanitizer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Certification type management.
 *
 * All mutations use explicit validation — no mass assignment from
 * raw request data ($request->all() removed).
 */
class CertificationTypeController extends Controller
{
    public function __construct(private AuditLogger $auditLogger) {}

    private function certColumns(): array
    {
        return [
            'certificate_type_id',
            'certificate_name',
            'certificate_requirements',
            'certificate_process_period',
            'access_id',
            'layout_header_left_url',
            'layout_header_right_url',
            'layout_footer_urls',
            'layout_header_logo_size',
            'layout_footer_logo_size',
            'is_archived',
            'archived_on',
            'archived_by',
            // Added alongside the 2026_08_29 logbook_category /
            // requires_source_submission migration — without these two
            // here, every response from this controller (index, show,
            // store, update, layouts) would silently drop both columns
            // even though they're set correctly in the DB and validated
            // correctly by Store/UpdateCertificationTypeRequest. This
            // whitelist is the only thing standing between the DB row
            // and the JSON response, so new columns must be added here
            // explicitly — they are not picked up automatically.
            'logbook_category_id',
            'requires_source_submission',
            // FIXED (same bug, found again): fulfillment_track_id existed
            // on the column, the model, and (as of this same change) the
            // FormRequest validation rules — but was missing from this
            // whitelist, so it was silently dropped from every response
            // here even after being correctly saved to the DB.
            'fulfillment_track_id',
            // FIXED (same bug, found a third time — see the two comments
            // above): cashier_document_patterns has been on the column,
            // the model's $fillable/$casts, and every audit log entry
            // (see store()/update() below) since the cashier-matching
            // subsystem shipped, but was never added to this whitelist —
            // so index/show/layouts/store/update all silently dropped it
            // from the JSON response for certificates specifically, even
            // though the exact same field worked fine on DocumentType
            // (whose controller has no such select() whitelist at all).
            // In practice this meant the admin UI had no way to display a
            // certificate type's existing cashier patterns back to the
            // user, regardless of how they got set.
            'cashier_document_patterns',
            // FIXED (same bug, found a fourth time — see the three comments
            // above): is_free_eligible and free_issuance_limit have been on
            // the column, the model's $fillable/$casts, and the
            // FreeIssuanceEligibilitySeeder (FESPEC-0008) since Phase 1 —
            // but were never added to this whitelist, so index/show/store/
            // update all silently dropped them from the JSON response for
            // certificates specifically. The exact same fields worked fine
            // on DocumentType (whose controller has no such select()
            // whitelist), which is why LOA and TOR appeared correctly in
            // the Free Requests catalog while COG never did — the frontend
            // filters on `is_free_eligible === true`, which is always
            // `undefined` here without this line.
            //
            // See CertificationTypeWhitelistCompletenessTest for a
            // standing regression guard against this recurring bug class:
            // it fails the build the next time a certificate_type column
            // is added but not mirrored here, instead of silently
            // shipping a field that quietly vanishes from every response.
            'is_free_eligible',
            'free_issuance_limit',
        ];
    }

    private function freshRecord(int $id): CertificationType
    {
        return CertificationType::query()
            ->select($this->certColumns())
            ->where('certificate_type_id', $id)
            ->first();
    }

    public function layouts()
    {
        return response()->json(
            CertificationType::query()
                ->select($this->certColumns())
                ->orderBy('certificate_name')
                ->get(),
            200
        );
    }

    /**
     * List certification types.
     *
     * Excludes archived items by default — see DocumentTypeController::
     * index() for the full rationale; this endpoint has the identical
     * shared-audience problem via getCertifications() in api.js.
     */
    public function index(Request $request)
    {
        $includeArchived = $request->boolean('include_archived')
            && Auth::user()
            && in_array((int) Auth::user()->role_id, [3, 4], true);

        $query = CertificationType::query()->select($this->certColumns());

        if (!$includeArchived) {
            $query->where('is_archived', false);
        }

        return response()->json($query->get(), 200);
    }

    public function show($id)
    {
        $cert = CertificationType::find($id);
        if (!$cert) {
            return response()->json(['message' => 'Certification type not found'], 404);
        }

        return response()->json($this->freshRecord($id), 200);
    }

    public function store(StoreCertificationTypeRequest $request)
    {
        $validated = $this->withSanitizedPatterns($request->validated());

        $cert = CertificationType::create($validated);

        /** @var SystemUser $actor */
        $actor = Auth::user();

        $this->auditLogger->log($request, $actor, AuditLog::ACTION_CERTIFICATE_TYPE_CREATED, [
            'certificate_type_id'       => $cert->certificate_type_id,
            'certificate_name'          => $cert->certificate_name,
            'cashier_document_patterns' => $cert->cashier_document_patterns,
            'fulfillment_track_id'      => $cert->fulfillment_track_id,
            'logbook_category_id'       => $cert->logbook_category_id,
        ]);

        return response()->json($this->freshRecord($cert->certificate_type_id), 201);
    }

    public function update(UpdateCertificationTypeRequest $request, $id)
    {
        $cert = CertificationType::find($id);
        if (!$cert) {
            return response()->json(['message' => 'Certification type not found'], 404);
        }

        $validated = $this->withSanitizedPatterns($request->validated());
        $cert->update($validated);

        /** @var SystemUser $actor */
        $actor = Auth::user();

        $this->auditLogger->log($request, $actor, AuditLog::ACTION_CERTIFICATE_TYPE_UPDATED, [
            'certificate_type_id'       => $cert->certificate_type_id,
            'certificate_name'          => $cert->certificate_name,
            'changed_fields'            => array_keys($validated),
            'cashier_document_patterns' => $cert->cashier_document_patterns,
            'fulfillment_track_id'      => $cert->fulfillment_track_id,
            'logbook_category_id'       => $cert->logbook_category_id,
        ]);

        return response()->json($this->freshRecord($id), 200);
    }

    public function destroy(Request $request, $id)
    {
        $cert = CertificationType::find($id);
        if (!$cert) {
            return response()->json(['message' => 'Certification type not found'], 404);
        }

        try {
            $cert->delete();
        } catch (\Illuminate\Database\QueryException $e) {
            // MySQL error 1451 — FK constraint violation
            if ($e->getCode() === '23000') {
                return response()->json([
                    'message' => 'Cannot delete a certification type that is referenced by existing document requests.',
                ], 409);
            }

            throw $e;
        }

        /** @var SystemUser $actor */
        $actor = Auth::user();

        $this->auditLogger->log($request, $actor, AuditLog::ACTION_CERTIFICATE_TYPE_DELETED, [
            'certificate_type_id' => $cert->certificate_type_id,
            'certificate_name'    => $cert->certificate_name,
        ]);

        return response()->json(['message' => 'Certification type deleted'], 200);
    }

    // -------------------------------------------------------------------------
    // Archive / Restore — reversible, distinct from destroy() above.
    //
    // Per the Archive Policy — Document & Certificate Management:
    //   - A certificate type may only be archived if no request using it is
    //     still Processing or Ready to Claim ("active").
    //   - Archiving automatically locks the template (enforced in
    //     updateLayout()/uploadLayoutLogo() below via is_archived — no
    //     separate "locked" flag needed, and restoring unlocks it for free).
    //   - Every archive/restore records who did it, when, and (for
    //     archives) why.
    // -------------------------------------------------------------------------

    public function archive(ArchiveCertificationTypeRequest $request, $id)
    {
        $cert = CertificationType::find($id);
        if (!$cert) {
            return response()->json(['message' => 'Certification type not found'], 404);
        }

        if ($cert->is_archived) {
            return response()->json($this->freshRecord($id), 200);
        }

        $activeCount = $cert->activeRequestsCount();
        if ($activeCount > 0) {
            return response()->json([
                'message' => sprintf(
                    '%d active %s using this — can\'t archive yet.',
                    $activeCount,
                    $activeCount === 1 ? 'request is' : 'requests are'
                ),
                'active_requests' => $activeCount,
            ], 422);
        }

        $validated = $request->validated();

        /** @var SystemUser $actor */
        $actor = Auth::user();

        DB::transaction(function () use ($cert, $actor) {
            $cert->update([
                'is_archived' => true,
                'archived_on' => now(),
                'archived_by' => $actor->user_id,
            ]);
        });

        $this->auditLogger->log($request, $actor, AuditLog::ACTION_CERTIFICATE_TYPE_ARCHIVED, [
            'certificate_type_id' => $cert->certificate_type_id,
            'certificate_name'    => $cert->certificate_name,
            'reason'              => $validated['reason'] ?? null,
        ]);

        return response()->json($this->freshRecord($id), 200);
    }

    public function restore(Request $request, $id)
    {
        $cert = CertificationType::find($id);
        if (!$cert) {
            return response()->json(['message' => 'Certification type not found'], 404);
        }

        if (!$cert->is_archived) {
            return response()->json($this->freshRecord($id), 200);
        }

        /** @var SystemUser $actor */
        $actor = Auth::user();

        DB::transaction(function () use ($cert) {
            $cert->update([
                'is_archived' => false,
                'archived_on' => null,
                'archived_by' => null,
            ]);
        });

        $this->auditLogger->log($request, $actor, AuditLog::ACTION_CERTIFICATE_TYPE_RESTORED, [
            'certificate_type_id' => $cert->certificate_type_id,
            'certificate_name'    => $cert->certificate_name,
        ]);

        return response()->json($this->freshRecord($id), 200);
    }

    public function updateLayout(UpdateCertificationLayoutRequest $request, $id)
    {
        $cert = CertificationType::find($id);
        if (!$cert) {
            return response()->json(['message' => 'Certification type not found'], 404);
        }

        if ($cert->is_archived) {
            return response()->json([
                'message' => 'This certificate is archived — its template is read-only. Restore it first to make changes.',
            ], 423); // 423 Locked
        }

        $validated = $request->validated();

        if (array_key_exists('layout_footer_urls', $validated) && $validated['layout_footer_urls'] === null) {
            $validated['layout_footer_urls'] = [];
        }

        $cert->update($validated);

        return response()->json([
            'message' => 'Certification layout updated successfully',
            'data'    => $cert->fresh(),
        ], 200);
    }

    public function uploadLayoutLogo(UploadCertificationLayoutLogoRequest $request, $id)
    {
        $cert = CertificationType::find($id);
        if (!$cert) {
            return response()->json(['message' => 'Certification type not found'], 404);
        }

        if ($cert->is_archived) {
            return response()->json([
                'message' => 'This certificate is archived — its template is read-only. Restore it first to make changes.',
            ], 423); // 423 Locked
        }

        $validated = $request->validated();

        $slot = $validated['slot'] ?? 'footer';

        // Use the default disk from FILESYSTEM_DISK env var (e.g. 's3' in production).
        // Storing on 'public' (local) while Storage::url() resolves via S3 caused
        // images to be unreadable — the file existed on local disk but the URL
        // pointed at S3 where nothing was uploaded.
        $disk = config('filesystems.default', 'public');
        $path = $request->file('logo')->store("certification-layouts/{$id}/{$slot}", $disk);

        // Persist the resolved public URL into the correct layout column so the
        // model accessors don't need to re-resolve it later (S3 pre-signed URLs
        // differ from local Storage::url() paths).
        $url = Storage::disk($disk)->url($path);

        $column = match ($slot) {
            'header_left'  => 'layout_header_left_url',
            'header_right' => 'layout_header_right_url',
            default        => null, // footer: caller appends via updateLayout
        };

        if ($column) {
            // Store the full public URL for header slots so the accessor returns
            // the correct S3 URL without double-prefixing.
            $cert->update([$column => $url]);
        }

        return response()->json([
            'message' => 'Logo uploaded successfully',
            'data'    => [
                'slot' => $slot,
                'path' => $path,
                // Full public URL — S3 object URL in production, /storage/... locally.
                // The frontend should use this directly as the new layout URL value.
                'url'  => $url,
            ],
        ], 201);
    }

    /**
     * If the validated payload included cashier_document_patterns, replace
     * it with the sanitized version before it's written to the DB. See
     * DocumentTypeController::withSanitizedPatterns() for the full
     * rationale — identical behaviour, mirrored here for certificates.
     */
    private function withSanitizedPatterns(array $validated): array
    {
        if (array_key_exists('cashier_document_patterns', $validated)) {
            $validated['cashier_document_patterns'] = CashierPatternSanitizer::sanitize(
                $validated['cashier_document_patterns'] ?? []
            );
        }

        return $validated;
    }
}