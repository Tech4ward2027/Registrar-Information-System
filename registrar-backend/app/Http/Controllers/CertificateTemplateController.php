<?php

namespace App\Http\Controllers;

use App\Exceptions\ArchivedCertificationTypesException;
use App\Exceptions\InvalidCertificateTemplateException;
use App\Exceptions\TemplateStorageException;
use App\Http\Requests\CertificationType\UpsertCertificateTemplateRequest;
use App\Http\Resources\CertificateTemplateResource;
use App\Models\CertificationType;
use App\Models\SystemUser;
use App\Services\Certificates\CertificateTemplateService;
use App\Services\Certificates\MergeTags\MergeTagRegistry;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * Database-driven certificate templates (Phase 2). All routes are role:3.
 *
 * Response contract for the legacy fallback: GET certifications/{id}/template
 * always answers 200 with `source`:
 *   "database" — an active template exists; `template` carries metadata + body
 *   "legacy"   — none exists; the client keeps using its built-in CERT_CONFIG
 */
class CertificateTemplateController extends Controller
{
    public function __construct(
        private readonly CertificateTemplateService $templates,
        private readonly MergeTagRegistry $registry,
    ) {}

    /** GET certifications/templates — active version of every type that has one (no bodies). */
    public function summary(): JsonResponse
    {
        return response()->json([
            'data' => CertificateTemplateResource::collection($this->templates->activeSummaries())->resolve(),
        ]);
    }

    /** GET certificate-merge-tags — the complete list of tags a template may use. */
    public function mergeTags(): JsonResponse
    {
        return response()->json([
            'data'    => $this->registry->toArray(),
            'filters' => MergeTagRegistry::FILTERS,
        ]);
    }

    /** GET certifications/{id}/template */
    public function show(Request $request, int $id): JsonResponse
    {
        if (!CertificationType::query()->whereKey($id)->exists()) {
            return $this->notFound();
        }

        $template = $this->templates->activeFor($id);
        if ($template === null) {
            return response()->json(['data' => ['source' => 'legacy', 'template' => null]]);
        }

        try {
            $content = $this->templates->contentFor($template);
        } catch (TemplateStorageException $e) {
            return $this->storageUnavailable($e, $id);
        }

        return response()->json(['data' => [
            'source'   => 'database',
            'template' => array_merge(
                (new CertificateTemplateResource($template))->resolve($request),
                ['content' => $content],
            ),
        ]]);
    }

    /** GET certifications/{id}/template/versions — full history, newest first, no bodies. */
    public function versions(Request $request, int $id): JsonResponse
    {
        if (!CertificationType::query()->whereKey($id)->exists()) {
            return $this->notFound();
        }

        return response()->json([
            'data' => CertificateTemplateResource::collection($this->templates->versions($id))->resolve($request),
        ]);
    }

    /**
     * PUT certifications/{id}/template
     * 201 = a new version was created; 200 = identical to the active version, nothing written.
     */
    public function upsert(UpsertCertificateTemplateRequest $request, int $id): JsonResponse
    {
        if (!CertificationType::query()->whereKey($id)->exists()) {
            return $this->notFound();
        }

        /** @var SystemUser $actor */
        $actor = Auth::user();

        try {
            $result = $this->templates->save($request, $actor, $id, $request->validated());
        } catch (InvalidCertificateTemplateException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => $e->errors], 422);
        } catch (ArchivedCertificationTypesException) {
            return $this->archived();
        } catch (TemplateStorageException $e) {
            return $this->storageUnavailable($e, $id);
        }

        $created = $result['created'];

        return response()->json([
            'message' => $created
                ? 'Certificate template saved as version ' . $result['template']->version . '.'
                : 'No changes — the active template version is unchanged.',
            'changed' => $created,
            'data'    => (new CertificateTemplateResource($result['template']))->resolve($request),
        ], $created ? 201 : 200);
    }

    /** POST certifications/{id}/template/versions/{version}/activate */
    public function activate(Request $request, int $id, int $version): JsonResponse
    {
        /** @var SystemUser $actor */
        $actor = Auth::user();

        try {
            $template = $this->templates->activate($request, $actor, $id, $version);
        } catch (ModelNotFoundException) {
            return $this->notFound('Certificate template version not found.');
        } catch (ArchivedCertificationTypesException) {
            return $this->archived();
        }

        return response()->json([
            'message' => "Template version {$template->version} is now active.",
            'data'    => (new CertificateTemplateResource($template))->resolve($request),
        ]);
    }

    private function notFound(string $message = 'Certification type not found'): JsonResponse
    {
        return response()->json(['message' => $message], 404);
    }

    private function archived(): JsonResponse
    {
        return response()->json([
            'message' => 'This certificate is archived — its template is read-only. Restore it first to make changes.',
        ], 423);
    }

    private function storageUnavailable(TemplateStorageException $e, int $certificateTypeId): JsonResponse
    {
        // Details go to the log only; the client gets a generic message.
        Log::error('Certificate template storage failure', [
            'certificate_type_id' => $certificateTypeId,
            'error'               => $e->getMessage(),
            'previous'            => $e->getPrevious()?->getMessage(),
        ]);

        return response()->json(['message' => 'Certificate template storage is temporarily unavailable.'], 503);
    }
}
