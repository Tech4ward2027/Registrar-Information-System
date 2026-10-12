<?php

namespace App\Http\Controllers;

use App\Exceptions\ArchivedCertificationTypesException;
use App\Exceptions\CertificateGenerationException;
use App\Exceptions\MissingMergeFieldsException;
use App\Exceptions\TemplateStorageException;
use App\Http\Requests\CertificationType\GenerateCertificateRequest;
use App\Models\SystemUser;
use App\Services\Certificates\CertificateGenerationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * POST /api/certificates/generate (Phase 3)
 *
 * Success: 200 application/pdf, streamed inline so the frontend can preview,
 * download or print the same bytes. The control number travels in the
 * X-Control-Number header (exposed to the browser via CORS config) and is also
 * the download filename. Nothing is stored: the response body is the only copy.
 *
 * Failure: JSON `{message, code, ...}`:
 *   404 student_not_found / request_not_found / certificate_type_not_found
 *   409 template_not_configured  (+ source: "legacy" — keep using CERT_CONFIG)
 *   422 validation, missing required fields, request/student mismatch, template_invalid
 *   423 certificate type archived
 *   503 template storage unavailable
 *   500 generation_failed (the request was NOT changed)
 */
class CertificateGenerationController extends Controller
{
    public function __construct(private readonly CertificateGenerationService $generator) {}

    public function __invoke(GenerateCertificateRequest $request): Response|JsonResponse
    {
        /** @var SystemUser $actor */
        $actor = Auth::user();

        try {
            $certificate = $this->generator->generate($request, $actor, $request->validated());
        } catch (CertificateGenerationException $e) {
            return response()->json(
                ['message' => $e->getMessage(), 'code' => $e->errorCode] + $e->context,
                $e->status,
            );
        } catch (MissingMergeFieldsException $e) {
            return response()->json([
                'message' => 'Some required certificate fields have no value.',
                'code'    => 'missing_required_fields',
                'errors'  => ['fields' => $e->fields],
            ], 422);
        } catch (ArchivedCertificationTypesException) {
            return response()->json([
                'message' => 'This certificate is archived — restore it before generating.',
                'code'    => 'certificate_type_archived',
            ], 423);
        } catch (TemplateStorageException $e) {
            // The template is deliberately not swapped for legacy wording here:
            // printing the wrong text on an official certificate is worse than
            // asking the Registrar to retry.
            Log::error('Certificate template storage unavailable during generation.', [
                'certificate_type_id' => $request->integer('certificate_type_id'),
                'reason'              => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Certificate template storage is temporarily unavailable.',
                'code'    => 'template_storage_unavailable',
            ], 503);
        }

        return response($certificate->pdf, 200, [
            'Content-Type'           => 'application/pdf',
            'Content-Length'         => (string) strlen($certificate->pdf),
            'Content-Disposition'    => 'inline; filename="' . $certificate->filename() . '"',
            'X-Control-Number'       => $certificate->controlNumber,
            'X-Template-Version'     => (string) $certificate->templateVersion,
            'X-Generation-Id'        => (string) $certificate->generationId,
            // Personal data in a certificate: never cache it in the browser or a proxy.
            'Cache-Control'          => 'no-store, private',
            'Pragma'                 => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
