<?php

namespace App\Services\Certificates;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Turns a stored layout logo URL into a data: URI the PDF can embed.
 *
 * The PDF engine is never allowed to fetch URLs (that would be an SSRF
 * surface: a logo column pointing at an internal address would make the
 * server request it). Instead the logo is read from the app's OWN disk, and
 * only when the stored URL belongs to that disk. Anything else — a foreign
 * host, a missing object, a non-image, an oversized file — is skipped, and
 * the certificate renders without that logo rather than failing.
 */
class CertificateLayoutAssets
{
    private const ALLOWED_MIME = ['image/png', 'image/jpeg', 'image/gif'];

    public function dataUri(?string $storedUrl): ?string
    {
        if ($storedUrl === null || trim($storedUrl) === '') {
            return null;
        }

        try {
            $diskName = config('filesystems.default', 'public');
            $disk     = Storage::disk($diskName);
            $path     = $this->pathOnDisk($storedUrl, rtrim((string) $disk->url(''), '/') . '/');

            if ($path === null || !$disk->exists($path)) {
                return null;
            }

            $max = (int) config('certificate_templates.pdf.max_logo_bytes', 2_000_000);
            if ($disk->size($path) > $max) {
                return null;
            }

            $bytes = $disk->get($path);
            $info  = is_string($bytes) ? @getimagesizefromstring($bytes) : false;
            if ($info === false || !in_array($info['mime'] ?? '', self::ALLOWED_MIME, true)) {
                return null;
            }

            return 'data:' . $info['mime'] . ';base64,' . base64_encode($bytes);
        } catch (Throwable $e) {
            Log::warning('Certificate layout logo skipped.', ['reason' => $e->getMessage()]);

            return null;
        }
    }

    /** The disk-relative path for a URL under this disk's base URL, or a bare path; null for anything else. */
    private function pathOnDisk(string $storedUrl, string $baseUrl): ?string
    {
        $value = trim($storedUrl);

        if (str_starts_with($value, $baseUrl)) {
            $value = substr($value, strlen($baseUrl));
        } elseif (str_starts_with($value, 'http://') || str_starts_with($value, 'https://') || str_starts_with($value, '//')) {
            return null; // a URL that is not on this disk is never fetched
        }

        // Drop any query string (pre-signed URLs), decode, THEN refuse
        // traversal — checking before decoding would let %2e%2e through.
        $value = rawurldecode(ltrim(explode('?', $value, 2)[0], '/'));
        if ($value === '' || str_contains($value, '..') || str_contains($value, "\0") || str_contains($value, '\\')) {
            return null;
        }

        return $value;
    }
}
