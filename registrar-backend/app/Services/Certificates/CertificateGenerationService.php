<?php

namespace App\Services\Certificates;

use App\DTOs\AcademicRecords\ResolvedAcademicRecord;
use App\DTOs\Certificates\GeneratedCertificate;
use App\Enums\CertificateGenerationStatusEnum;
use App\Enums\RequestStatusEnum;
use App\Exceptions\ArchivedCertificationTypesException;
use App\Exceptions\CertificateGenerationException;
use App\Exceptions\InvalidCertificateTemplateException;
use App\Exceptions\MissingMergeFieldsException;
use App\Exceptions\TemplateStorageException;
use App\Models\AuditLog;
use App\Models\CertificateGeneration;
use App\Models\CertificateTemplate;
use App\Models\CertificationType;
use App\Models\DocumentRequest;
use App\Models\RequestCertificate;
use App\Models\Signatory;
use App\Models\SystemUser;
use App\Services\AcademicRecords\AcademicRecordResolver;
use App\Services\AuditLogger;
use App\Services\DocumentRequestService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

/**
 * Generates one certificate PDF on the server.
 *
 *   validate -> resolve (type, template, student, request) -> check required
 *   fields -> RESERVE control number -> render + build PDF -> finalise
 *
 * Why the reservation is its own short transaction: building a PDF takes
 * long enough that holding the per-year counter lock for it would serialise
 * every Registrar behind one render. So the number is reserved and committed
 * first (status `pending`), the PDF is built with no lock held, and a second
 * transaction records the outcome. If anything after the reservation fails,
 * the record becomes `failed` and the request is NOT touched: the cost is a
 * visible, explained gap in the numbering, never a half-completed request.
 *
 * Everything that can be rejected by the caller's input (unknown student,
 * mismatched request, missing required field, no active template) is checked
 * BEFORE the reservation, so ordinary mistakes never burn a number.
 *
 * The PDF exists only in memory and in the HTTP response; it is not written
 * to the object store or the database.
 */
class CertificateGenerationService
{
    public function __construct(
        private readonly CertificateTemplateService $templates,
        private readonly AcademicRecordResolver $academicRecords,
        private readonly CertificateDataResolver $data,
        private readonly TemplateRenderer $renderer,
        private readonly CertificatePdfRenderer $pdf,
        private readonly ControlNumberGenerator $controlNumbers,
        private readonly DocumentRequestService $requests,
        private readonly AuditLogger $auditLogger,
    ) {}

    /**
     * @param array{certificate_type_id: int|string, student_num: string,
     *              request_id?: ?int, request_certificate_id?: ?int,
     *              signatory_id?: ?int, fields?: array<string, mixed>} $input validated input
     *
     * @throws CertificateGenerationException
     * @throws ArchivedCertificationTypesException
     * @throws MissingMergeFieldsException
     * @throws TemplateStorageException
     * @throws HttpException  when the request workflow rejects the update (e.g. archived meanwhile)
     */
    public function generate(Request $request, SystemUser $actor, array $input): GeneratedCertificate
    {
        // ── 1. Everything the caller can get wrong, before any write ─────────
        $type = CertificationType::query()->find((int) $input['certificate_type_id'])
            ?? throw CertificateGenerationException::certificateTypeNotFound();

        if ($type->is_archived) {
            throw new ArchivedCertificationTypesException([(int) $type->certificate_type_id]);
        }

        // No active database template -> the client keeps its legacy CERT_CONFIG.
        $template = $this->templates->activeFor((int) $type->certificate_type_id)
            ?? throw CertificateGenerationException::templateNotConfigured();

        $body = $this->templates->contentFor($template); // TemplateStorageException -> 503

        $record = $this->academicRecords->resolve((string) $input['student_num'])
            ?? throw CertificateGenerationException::studentNotFound();

        [$documentRequest, $item] = $this->workflowTarget($input, $record, (int) $type->certificate_type_id);

        $signatory = isset($input['signatory_id'])
            ? Signatory::query()->find((int) $input['signatory_id'])
            : null;

        $now  = CarbonImmutable::now(config('app.display_timezone', 'Asia/Manila'));
        $data = $this->data->build(
            $record,
            $this->academicRecords->latestOrNumber($record->userId),
            $signatory,
            $input['fields'] ?? [],
            $now,
        );

        // control_number does not exist yet; it is added right after the reservation.
        $required = $template->required_fields ?? [];
        $missing  = $this->renderer->missingRequired(array_values(array_diff($required, ['control_number'])), $data);
        if ($missing !== []) {
            throw new MissingMergeFieldsException($missing);
        }

        // ── 2. Reserve the control number (short transaction) ────────────────
        $generation = DB::transaction(function () use ($actor, $type, $template, $record, $documentRequest, $item, $data, $now) {
            $control = $this->controlNumbers->next($now);

            return CertificateGeneration::create([
                'control_number'          => $control,
                'certificate_type_id'     => (int) $type->certificate_type_id,
                'certificate_template_id' => (int) $template->certificate_template_id,
                'template_version'        => (int) $template->version,
                'requester_user_id'       => $record->userId,
                'student_number'          => $record->studentNumber,
                'request_id'              => $documentRequest?->request_id,
                'request_certificate_id'  => $item?->request_certificate_id,
                'status'                  => CertificateGenerationStatusEnum::Pending,
                'payload_snapshot'        => config('certificate_templates.store_payload_snapshot', true)
                    ? array_merge($data, ['control_number' => $control])
                    : null,
                'generated_by'            => $actor->user_id,
            ]);
        });

        // ── 3. Build the PDF with no lock held, then record the outcome ──────
        try {
            $data['control_number'] = $generation->control_number;

            $html = $this->renderer->render($body, $data, $required);
            $pdf  = $this->pdf->render($html, $template, $type, $generation->control_number);

            DB::transaction(function () use ($request, $actor, $generation, $template, $documentRequest, $item) {
                $generation->update([
                    'status'       => CertificateGenerationStatusEnum::Generated,
                    'generated_at' => now(),
                ]);

                // The existing "real generated signal" the workflow already
                // reads. Same locks/guards as POST .../mark-certificates-generated
                // (archived => 422), inside THIS transaction so the record, the
                // request and the audit row commit together or not at all.
                if ($documentRequest !== null && $item !== null) {
                    $this->requests->markCertificatesGenerated($documentRequest, (int) $item->request_certificate_id);
                }

                $this->auditLogger->log($request, $actor, AuditLog::ACTION_CERTIFICATE_GENERATED, [
                    'target_user_id'        => $generation->requester_user_id,
                    'control_number'        => $generation->control_number,
                    'certificate_type_id'   => $generation->certificate_type_id,
                    'template_version'      => (int) $template->version,
                    'generation_id'         => $generation->certificate_generation_id,
                    'request_id'            => $generation->request_id,
                    'request_certificate_id' => $generation->request_certificate_id,
                ]);
            });
        } catch (Throwable $e) {
            $this->recordFailure($request, $actor, $generation, $template, $e);

            throw $this->translate($e);
        }

        return new GeneratedCertificate(
            pdf:             $pdf,
            controlNumber:   $generation->control_number,
            templateVersion: (int) $template->version,
            generationId:    (int) $generation->certificate_generation_id,
        );
    }

    /**
     * Validates the optional request/item the certificate is being issued for.
     *
     * @return array{0: ?DocumentRequest, 1: ?RequestCertificate}
     *
     * @throws CertificateGenerationException
     */
    private function workflowTarget(array $input, ResolvedAcademicRecord $record, int $certificateTypeId): array
    {
        if (empty($input['request_id'])) {
            return [null, null]; // ad-hoc generation: nothing in the workflow to update
        }

        // DocumentRequest's global scopes already hide archived/deleted rows.
        $documentRequest = DocumentRequest::query()->find((int) $input['request_id'])
            ?? throw CertificateGenerationException::requestNotFound();

        if ($record->userId === null || (int) $documentRequest->user_id !== (int) $record->userId) {
            throw CertificateGenerationException::requestMismatch(
                'That request does not belong to the student number provided.',
                'request_student_mismatch',
            );
        }

        if (RequestStatusEnum::tryFrom((int) $documentRequest->status_id)?->isTerminal()) {
            throw CertificateGenerationException::requestMismatch(
                'This request is already closed; a certificate can no longer be generated for it.',
                'request_closed',
            );
        }

        $items = $documentRequest->certificates()->where('certificate_type_id', $certificateTypeId);

        if (!empty($input['request_certificate_id'])) {
            $item = $documentRequest->certificates()
                ->where('request_certificate_id', (int) $input['request_certificate_id'])
                ->first();

            if ($item === null || (int) $item->certificate_type_id !== $certificateTypeId) {
                throw CertificateGenerationException::requestMismatch(
                    'That certificate item does not belong to this request or is a different certificate type.',
                    'certificate_item_mismatch',
                );
            }
        } else {
            // Prefer a not-yet-generated item so a second certificate of the
            // same type on one request lands on the right row.
            $item = $items->orderByRaw('generated_at IS NULL DESC')->orderBy('request_certificate_id')->first();

            if ($item === null) {
                throw CertificateGenerationException::requestMismatch(
                    'This request does not include that certificate type.',
                    'request_missing_certificate',
                );
            }
        }

        if ($item->status_id !== null && RequestStatusEnum::tryFrom((int) $item->status_id)?->isTerminal()) {
            throw CertificateGenerationException::requestMismatch(
                'This certificate item is already closed; it can no longer be generated.',
                'certificate_item_closed',
            );
        }

        return [$documentRequest, $item];
    }

    /**
     * Mark the reservation failed and leave the request untouched. Never
     * throws: a problem here must not hide the original failure.
     */
    private function recordFailure(Request $request, SystemUser $actor, CertificateGeneration $generation, CertificateTemplate $template, Throwable $e): void
    {
        $code = match (true) {
            $e instanceof InvalidCertificateTemplateException => 'template_invalid',
            $e instanceof MissingMergeFieldsException         => 'missing_fields',
            $e instanceof HttpException                       => 'workflow_rejected',
            default                                           => 'render_error',
        };

        Log::error('Certificate generation failed.', [
            'control_number' => $generation->control_number,
            'failure_code'   => $code,
            'exception'      => $e::class,
            'message'        => $e->getMessage(),
        ]);

        try {
            // Re-read: the in-memory model may already say "generated" if the
            // failure happened inside the finalising transaction (which rolled back).
            $fresh = CertificateGeneration::query()->find($generation->certificate_generation_id);
            $fresh?->update(['status' => CertificateGenerationStatusEnum::Failed, 'failure_code' => $code]);

            $this->auditLogger->log($request, $actor, AuditLog::ACTION_CERTIFICATE_GENERATION_FAILED, [
                'target_user_id'      => $generation->requester_user_id,
                'control_number'      => $generation->control_number,
                'certificate_type_id' => $generation->certificate_type_id,
                'template_version'    => (int) $template->version,
                'failure_code'        => $code,
                'request_id'          => $generation->request_id,
            ]);
        } catch (Throwable $inner) {
            Log::error('Could not record certificate generation failure.', [
                'control_number' => $generation->control_number,
                'message'        => $inner->getMessage(),
            ]);
        }
    }

    /** Failures the caller should see as-is pass through; everything else becomes a generic error. */
    private function translate(Throwable $e): Throwable
    {
        return match (true) {
            $e instanceof CertificateGenerationException,
            $e instanceof MissingMergeFieldsException,
            $e instanceof HttpException                       => $e,
            $e instanceof InvalidCertificateTemplateException => CertificateGenerationException::templateInvalid(),
            default                                           => CertificateGenerationException::renderFailed(),
        };
    }
}
