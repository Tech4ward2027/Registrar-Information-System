<?php

namespace App\Services\Certificates;

use App\Exceptions\TemplateStorageException;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Thin wrapper over the object store that holds certificate TEMPLATE bodies.
 *
 * Layout:  {prefix}/{slug}/template-v{n}.html
 *          e.g. certificate-templates/graduation/template-v2.html
 *
 * Objects are private (the production bucket blocks all public access) and are
 * only ever read server-side through this class — no URL is ever handed out.
 * Objects are write-once per version path. Generated certificate PDFs are NOT
 * written here or anywhere else; see config/certificate_templates.php.
 */
class CertificateTemplateStorage
{
    public function disk(): Filesystem
    {
        return Storage::disk((string) config('certificate_templates.disk'));
    }

    public function pathFor(string $slug, int $version): string
    {
        $prefix = trim((string) config('certificate_templates.prefix', 'certificate-templates'), '/');

        return "{$prefix}/{$slug}/template-v{$version}.html";
    }

    /** @throws TemplateStorageException */
    public function put(string $path, string $content): void
    {
        try {
            // No per-object ACL/visibility option on purpose: new S3 buckets have
            // ACLs disabled and reject ACL headers. Privacy comes from the
            // bucket's Block Public Access + the fact that no URL is ever issued.
            $ok = $this->disk()->put($path, $content);
        } catch (Throwable $e) {
            throw new TemplateStorageException('Could not write the certificate template to storage.', 0, $e);
        }

        if ($ok === false) {
            throw new TemplateStorageException('Could not write the certificate template to storage.');
        }
    }

    /** @throws TemplateStorageException */
    public function get(string $path): string
    {
        try {
            $content = $this->disk()->get($path);
        } catch (Throwable $e) {
            throw new TemplateStorageException('Could not read the certificate template from storage.', 0, $e);
        }

        if (!is_string($content)) {
            throw new TemplateStorageException('The certificate template object is missing from storage.');
        }

        return $content;
    }

    /** Best-effort cleanup of an object written by a save that did not commit. */
    public function deleteQuietly(string $path): void
    {
        try {
            $this->disk()->delete($path);
        } catch (Throwable) {
            // An orphaned, unreferenced object is harmless; never mask the original failure.
        }
    }
}
