<?php

namespace App\Services\Alumni;

use App\DTOs\Alumni\AlumniDTO;
use App\Exceptions\AlumniSystemException;
use App\Models\SecurityEvent;
use App\Services\SecurityEventLogger;
use Illuminate\Support\Facades\Log;
use App\Contracts\AlumniSystemClientInterface;

/**
 * HTTP client for the PUP Alumni System (PUPTAPS) API.
 * Uses a static Bearer token — no OAuth flow needed.
 */
class AlumniSystemClient implements AlumniSystemClientInterface
{
    private string $baseUrl;
    private string $token;

    /**
     * $securityEvents is optional so `new AlumniSystemClient()` keeps
     * working; the container injects the singleton. When null, failure
     * recording is skipped.
     */
    public function __construct(private readonly ?SecurityEventLogger $securityEvents = null)
    {
        $this->baseUrl = rtrim(config('alumni.base_url'), '/');
        $this->token   = config('alumni.token');
    }

    // ── Endpoint methods ──────────────────────────────────────

    /** GET /alumni → paginated list with optional filters */
    public function listAlumni(array $filters = []): array
    {
        $data = $this->get('/alumni', $filters);

        return [
            'data'         => AlumniDTO::collectionFromArray($data['data'] ?? []),
            'total'        => $data['total'] ?? 0,
            'current_page' => $data['current_page'] ?? 1,
            'last_page'    => $data['last_page'] ?? 1,
            'per_page'     => $data['per_page'] ?? 20,
        ];
    }

    /** GET /alumni/{id} → single alumni by alumni_id or stud_number */
    public function getAlumni(string $id): AlumniDTO
    {
        return AlumniDTO::fromArray($this->get("/alumni/{$id}"));
    }

        /**
     * Safe lookup — returns null if Alumni System is unreachable or alumni not found.
     * RIS continues normally with its own data when this returns null.
     */
    public function tryGetAlumni(string $id): ?AlumniDTO
    {
        try {
            return $this->getAlumni($id);
        } catch (AlumniSystemException $e) {
            Log::warning('Alumni System unavailable — skipping enrichment', [
                'id'    => $id,
                'error' => $e->getMessage(),
                'code'  => $e->getCode(),
            ]);
            return null;
        }
    }

    /**
     * Safe list — returns empty array if Alumni System is unreachable.
     */
    public function tryListAlumni(array $filters = []): array
    {
        try {
            return $this->listAlumni($filters);
        } catch (AlumniSystemException $e) {
            Log::warning('Alumni System unavailable — returning empty list', [
                'filters' => $filters,
                'error'   => $e->getMessage(),
            ]);
            return [
                'data'         => [],
                'total'        => 0,
                'current_page' => 1,
                'last_page'    => 1,
                'per_page'     => 20,
            ];
        }
    }

    /** GET /alumni/lookup?email= → identity + academic fields only (no sensitive data) */
    public function getAlumniByEmail(string $email): AlumniDTO
    {
        return AlumniDTO::fromArray($this->get('/alumni/lookup', ['email' => $email]));
    }

    /**
     * Safe lookup — returns null if Alumni System is unreachable or this
     * email doesn't match a PUPTAPS alumnus. Used at SSO login time.
     */
    public function tryLookupAlumniByEmail(string $email): ?AlumniDTO
    {
        try {
            return $this->getAlumniByEmail($email);
        } catch (AlumniSystemException $e) {
            Log::warning('Alumni System unavailable — email lookup failed', [
                'email' => $email,
                'error' => $e->getMessage(),
                'code'  => $e->getCode(),
            ]);

            // System Health Phase 2a. A 404 is PUPTAPS's ordinary "no such
            // alumnus" answer (a NON_SIS alumnus legitimately gets it on
            // every login) — not a failure. Everything else (timeout,
            // 5xx, 401, bad JSON) is the system being unable to answer.
            // Recorded HERE, where the two cases are still distinguishable;
            // by the time this returns null to the caller they are not.
            // Best-effort: never throws, never changes what is returned.
            if ($e->getCode() !== 404 && $this->securityEvents !== null) {
                try {
                    $this->securityEvents->recordProvisioningFailed(
                        SecurityEvent::REASON_ALUMNI_LOOKUP_FAILED,
                        $email,
                        (int) $e->getCode() ?: null,
                    );
                } catch (\Throwable) {
                    // Intentionally swallowed.
                }
            }

            return null;
        }
    }

    // ── HTTP helpers ──────────────────────────────────────────

    private function get(string $path, array $query = []): array
    {
        $url = $this->baseUrl . $path . ($query ? '?' . http_build_query($query) : '');
        $ch  = curl_init($url);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_HTTPHEADER     => [
                "Authorization: Bearer {$this->token}",
                'Accept: application/json',
            ],
        ]);

        $body    = curl_exec($ch);
        $status  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);

        if ($curlErr) {
            throw new AlumniSystemException("Alumni System connection error: {$curlErr}", 503);
        }

        if ($status === 404) {
            throw new AlumniSystemException('Alumni not found.', 404);
        }

        if ($status === 401) {
            throw new AlumniSystemException('Invalid Alumni System API token.', 401);
        }

        if ($status !== 200) {
            Log::error('Alumni System request failed', [
                'path'   => $path,
                'status' => $status,
                'body'   => substr($body, 0, 500),
            ]);
            throw new AlumniSystemException("Alumni System returned {$status} for {$path}", $status);
        }

        $decoded = json_decode($body, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new AlumniSystemException('Alumni System returned invalid JSON.', 502);
        }

        // Unwrap { success, data } envelope
        return $decoded['data'] ?? $decoded;
    }
}