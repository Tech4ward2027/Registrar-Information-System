<?php

namespace App\Services\Certificates;

use App\Enums\CertificateOrientationEnum;
use App\Enums\CertificatePaperSizeEnum;
use App\Exceptions\ArchivedCertificationTypesException;
use App\Exceptions\InvalidCertificateTemplateException;
use App\Exceptions\TemplateStorageException;
use App\Models\AuditLog;
use App\Models\CertificateTemplate;
use App\Models\CertificationType;
use App\Models\SystemUser;
use App\Services\AuditLogger;
use App\Services\Certificates\MergeTags\MergeTagRegistry;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Owns the lifecycle of database-driven certificate templates.
 *
 *  - Metadata (version, paper, orientation, margins, required fields, active
 *    flag) lives in MySQL (`certificate_template`); the BODY lives in the
 *    private object store via CertificateTemplateStorage.
 *  - Every change to content or render settings creates a NEW immutable
 *    version; older versions are kept. Saving an identical template is a no-op.
 *  - All writes for one certificate type are serialized by a row lock on
 *    `certificate_type`, which is also what makes version numbers gap-free
 *    and guarantees a single active version.
 *  - Every version change is audited with the template version number.
 *
 * Legacy fallback: when a type has NO active template, activeFor() returns
 * null and callers use the frontend CERT_CONFIG (see CertificateTemplateController).
 */
class CertificateTemplateService
{
    public function __construct(
        private readonly CertificateTemplateStorage $storage,
        private readonly TemplateSanitizer $sanitizer,
        private readonly TemplateRenderer $renderer,
        private readonly MergeTagRegistry $registry,
        private readonly AuditLogger $auditLogger,
    ) {}

    // ── Reads ────────────────────────────────────────────────────────────────

    public function activeFor(int $certificateTypeId): ?CertificateTemplate
    {
        return CertificateTemplate::query()
            ->where('certificate_type_id', $certificateTypeId)
            ->where('is_active', true)
            ->first();
    }

    /** @return Collection<int, CertificateTemplate> newest version first, no bodies */
    public function versions(int $certificateTypeId): Collection
    {
        return CertificateTemplate::query()
            ->where('certificate_type_id', $certificateTypeId)
            ->orderByDesc('version')
            ->get();
    }

    /** @return Collection<int, CertificateTemplate> the active version of every type that has one */
    public function activeSummaries(): Collection
    {
        return CertificateTemplate::query()
            ->where('is_active', true)
            ->orderBy('certificate_type_id')
            ->get();
    }

    /**
     * The template body for a version, verified against the checksum saved
     * with it so a tampered or truncated object can never be rendered.
     *
     * @throws TemplateStorageException
     */
    public function contentFor(CertificateTemplate $template): string
    {
        $ttl = (int) config('certificate_templates.cache_ttl', 0);
        $key = "certificate-template:{$template->certificate_template_id}:{$template->content_sha256}";

        if ($ttl > 0 && is_string($cached = Cache::get($key))) {
            return $cached;
        }

        $content = $this->storage->get($template->storage_path);

        if (!hash_equals($template->content_sha256, hash('sha256', $content))) {
            throw new TemplateStorageException('The stored certificate template failed its integrity check.');
        }

        if ($ttl > 0) {
            Cache::put($key, $content, $ttl);
        }

        return $content;
    }

    // ── Writes ───────────────────────────────────────────────────────────────

    /**
     * Save a template for a certificate type, creating a new version when
     * anything changed.
     *
     * @param array{content: string, paper_size?: string, orientation?: string,
     *              margin_top?: float|int|string, margin_right?: float|int|string,
     *              margin_bottom?: float|int|string, margin_left?: float|int|string,
     *              required_fields?: list<string>, change_note?: ?string} $input
     * @return array{template: CertificateTemplate, created: bool}
     *
     * @throws InvalidCertificateTemplateException
     * @throws ArchivedCertificationTypesException
     * @throws TemplateStorageException
     */
    public function save(Request $request, SystemUser $actor, int $certificateTypeId, array $input): array
    {
        $content = $this->validatedContent((string) ($input['content'] ?? ''));
        $usedTags = $this->renderer->analyze($content)['tags'];

        $writtenPath = null;

        try {
            return DB::transaction(function () use ($request, $actor, $certificateTypeId, $input, $content, $usedTags, &$writtenPath) {
                $cert = $this->lockedType($certificateTypeId);
                $current = $this->activeFor($certificateTypeId);

                $settings = $this->resolveSettings($input, $current);
                $this->assertRequiredFields($settings['required_fields'], $usedTags);

                $sha = hash('sha256', $content);

                if ($current !== null && $this->isUnchanged($current, $sha, $settings)) {
                    return ['template' => $current, 'created' => false];
                }

                $latest = CertificateTemplate::query()
                    ->where('certificate_type_id', $certificateTypeId)
                    ->orderByDesc('version')
                    ->first();

                $version = ($latest?->version ?? 0) + 1;
                $slug = $latest?->template_slug ?? $this->slugFor($cert);
                $path = $this->storage->pathFor($slug, $version);

                // Object first, row second: a row never points at a missing
                // object. If anything below fails the object is removed again.
                $this->storage->put($path, $content);
                $writtenPath = $path;

                if ($current !== null) {
                    $current->update(['is_active' => false]);
                }

                $template = CertificateTemplate::create([
                    'certificate_type_id' => $certificateTypeId,
                    'version'             => $version,
                    'template_slug'       => $slug,
                    'storage_path'        => $path,
                    'content_sha256'      => $sha,
                    'paper_size'          => $settings['paper_size'],
                    'orientation'         => $settings['orientation'],
                    'margin_top'          => $settings['margins']['top'],
                    'margin_right'        => $settings['margins']['right'],
                    'margin_bottom'       => $settings['margins']['bottom'],
                    'margin_left'         => $settings['margins']['left'],
                    'required_fields'     => $settings['required_fields'],
                    'is_active'           => true,
                    'change_note'         => $this->cleanNote($input['change_note'] ?? null),
                    'created_by'          => $actor->user_id,
                ]);

                $this->auditLogger->log($request, $actor, AuditLog::ACTION_CERTIFICATE_TEMPLATE_VERSION_CREATED, [
                    'certificate_type_id'  => $certificateTypeId,
                    'template_version'     => $version,
                    'previous_version'     => $current?->version,
                    'template_slug'        => $slug,
                    'content_sha256'       => $sha,
                    'content_changed'      => $current === null || $current->content_sha256 !== $sha,
                    'paper_size'           => $settings['paper_size']->value,
                    'orientation'          => $settings['orientation']->value,
                    'required_field_count' => count($settings['required_fields']),
                ]);

                return ['template' => $template->fresh(), 'created' => true];
            });
        } catch (Throwable $e) {
            if ($writtenPath !== null) {
                $this->storage->deleteQuietly($writtenPath);
            }

            throw $e;
        }
    }

    /**
     * Make an existing version the active one again (rollback / roll-forward).
     *
     * @throws ModelNotFoundException
     * @throws ArchivedCertificationTypesException
     */
    public function activate(Request $request, SystemUser $actor, int $certificateTypeId, int $version): CertificateTemplate
    {
        return DB::transaction(function () use ($request, $actor, $certificateTypeId, $version) {
            $this->lockedType($certificateTypeId);

            $target = CertificateTemplate::query()
                ->where('certificate_type_id', $certificateTypeId)
                ->where('version', $version)
                ->firstOrFail();

            $current = $this->activeFor($certificateTypeId);
            if ($current !== null && $current->is($target)) {
                return $target;
            }

            // Deactivate first: the unique "one active per type" index would
            // otherwise reject two active rows, even momentarily.
            $current?->update(['is_active' => false]);
            $target->update(['is_active' => true]);

            $this->auditLogger->log($request, $actor, AuditLog::ACTION_CERTIFICATE_TEMPLATE_ACTIVATED, [
                'certificate_type_id' => $certificateTypeId,
                'template_version'    => $target->version,
                'previous_version'    => $current?->version,
                'template_slug'       => $target->template_slug,
                'content_sha256'      => $target->content_sha256,
            ]);

            return $target->fresh();
        });
    }

    // ── Internals ────────────────────────────────────────────────────────────

    /** @throws InvalidCertificateTemplateException */
    private function validatedContent(string $raw): string
    {
        if (mb_strlen($raw) > (int) config('certificate_templates.max_content_length')) {
            throw new InvalidCertificateTemplateException(['content' => ['The template is too long.']]);
        }

        $content = $this->sanitizer->sanitize($raw);

        if (trim($content) === '') {
            throw new InvalidCertificateTemplateException(['content' => [
                'The template is empty once unsupported markup is removed.',
            ]]);
        }

        $analysis = $this->renderer->analyze($content);
        if ($analysis['errors'] !== []) {
            throw new InvalidCertificateTemplateException(['content' => $analysis['errors']]);
        }

        return $content;
    }

    /**
     * Render settings for the new version: request value, else the active
     * version's, else the configured default.
     *
     * @return array{paper_size: CertificatePaperSizeEnum, orientation: CertificateOrientationEnum,
     *               margins: array{top: float, right: float, bottom: float, left: float},
     *               required_fields: list<string>}
     *
     * @throws InvalidCertificateTemplateException
     */
    private function resolveSettings(array $input, ?CertificateTemplate $current): array
    {
        $defaults = config('certificate_templates.defaults');

        $paper = $input['paper_size'] ?? $current?->paper_size ?? $defaults['paper_size'];
        $orientation = $input['orientation'] ?? $current?->orientation ?? $defaults['orientation'];

        $paper = $paper instanceof CertificatePaperSizeEnum ? $paper : CertificatePaperSizeEnum::tryFrom((string) $paper);
        $orientation = $orientation instanceof CertificateOrientationEnum ? $orientation : CertificateOrientationEnum::tryFrom((string) $orientation);

        if ($paper === null) {
            throw new InvalidCertificateTemplateException(['paper_size' => ['Unsupported paper size.']]);
        }
        if ($orientation === null) {
            throw new InvalidCertificateTemplateException(['orientation' => ['Unsupported orientation.']]);
        }

        $min = (float) config('certificate_templates.margin_min_mm');
        $max = (float) config('certificate_templates.margin_max_mm');
        $margins = [];
        foreach (['top', 'right', 'bottom', 'left'] as $side) {
            $value = $input["margin_{$side}"] ?? $current?->{"margin_{$side}"} ?? $defaults['margin_mm'];
            if (!is_numeric($value) || (float) $value < $min || (float) $value > $max) {
                throw new InvalidCertificateTemplateException(["margin_{$side}" => ["Margin must be between {$min} and {$max} mm."]]);
            }
            $margins[$side] = round((float) $value, 1);
        }

        $required = $input['required_fields'] ?? $current?->required_fields ?? [];
        $required = array_values(array_unique(array_map('strval', $required)));

        $unknown = array_values(array_filter($required, fn (string $n) => !$this->registry->has($n)));
        if ($unknown !== []) {
            throw new InvalidCertificateTemplateException(['required_fields' => [
                'Unknown merge field(s): ' . implode(', ', $unknown) . '.',
            ]]);
        }

        return [
            'paper_size'      => $paper,
            'orientation'     => $orientation,
            'margins'         => $margins,
            'required_fields' => $required,
        ];
    }

    /**
     * A required field the template never uses would block generation for no
     * reason, so it is rejected as an authoring mistake.
     *
     * @param list<string> $required
     * @param list<string> $usedTags
     * @throws InvalidCertificateTemplateException
     */
    private function assertRequiredFields(array $required, array $usedTags): void
    {
        $unused = array_values(array_diff($required, $usedTags));
        if ($unused !== []) {
            throw new InvalidCertificateTemplateException(['required_fields' => [
                'Required field(s) not used in the template: ' . implode(', ', $unused) . '.',
            ]]);
        }
    }

    private function isUnchanged(CertificateTemplate $current, string $sha, array $settings): bool
    {
        $currentRequired = $current->required_fields ?? [];
        $newRequired = $settings['required_fields'];
        sort($currentRequired);
        sort($newRequired);

        return $current->content_sha256 === $sha
            && $current->paper_size === $settings['paper_size']
            && $current->orientation === $settings['orientation']
            && $currentRequired === $newRequired
            && round($current->margin_top, 1) === $settings['margins']['top']
            && round($current->margin_right, 1) === $settings['margins']['right']
            && round($current->margin_bottom, 1) === $settings['margins']['bottom']
            && round($current->margin_left, 1) === $settings['margins']['left'];
    }

    /**
     * @throws ModelNotFoundException
     * @throws ArchivedCertificationTypesException
     */
    private function lockedType(int $certificateTypeId): CertificationType
    {
        $cert = CertificationType::query()
            ->where('certificate_type_id', $certificateTypeId)
            ->lockForUpdate()
            ->firstOrFail();

        if ($cert->is_archived) {
            throw new ArchivedCertificationTypesException([$certificateTypeId]);
        }

        return $cert;
    }

    /** Stable directory name for a type's templates, unique across types. */
    private function slugFor(CertificationType $cert): string
    {
        $slug = Str::slug((string) $cert->certificate_name);
        $slug = $slug === '' ? 'certificate' : Str::limit($slug, 50, '');

        $taken = CertificateTemplate::query()
            ->where('template_slug', $slug)
            ->where('certificate_type_id', '!=', $cert->certificate_type_id)
            ->exists();

        return $taken ? Str::limit($slug, 40, '') . '-' . $cert->certificate_type_id : $slug;
    }

    private function cleanNote(?string $note): ?string
    {
        $note = trim(preg_replace('/[\x00-\x1F\x7F]/u', ' ', (string) $note) ?? '');

        return $note === '' ? null : mb_substr($note, 0, 255);
    }
}
