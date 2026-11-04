<?php

namespace App\Services\Representative;

use App\Models\AuditLog;
use App\Models\DocumentRequest;
use App\Models\RequestorStatusFlag;
use App\Models\RequestRepresentative;
use App\Models\SystemUser;
use App\Services\AuditLogger;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Notification Policy §3.6: the authorised representative of a deceased or
 * permanently incapacitated requestor.
 *
 * Why this is strict: a verified representative receives mail about someone
 * else's record, so the entry is a two-person control. Staff add it (pending),
 * a Super Admin who is not the same person reviews the proof and verifies it.
 * Nothing is ever emailed to a pending or revoked representative.
 *
 * Proof documents (ID, relationship, death/incapacity evidence) are encrypted
 * with APP_KEY and written to a private disk under a random name. The row
 * stores references only. Every add, verify, revoke and proof view is audited,
 * and no audit entry carries the representative's name or email.
 */
class RequestRepresentativeService
{
    public function __construct(private AuditLogger $auditLogger) {}

    /**
     * @param  array{full_name:string,email:string,relationship:string}  $data
     * @param  array<string,UploadedFile>  $files  keyed by RequestRepresentative::PROOF_*
     */
    public function add(DocumentRequest $documentRequest, array $data, array $files, SystemUser $actor, Request $request): RequestRepresentative
    {
        $owner = SystemUser::find($documentRequest->user_id);
        if (! $owner) {
            abort(422, 'The requestor account for this request no longer exists.');
        }

        if (! RequestorStatusFlag::active()->where('user_id', $owner->user_id)->exists()) {
            abort(422, 'Mark the requestor as deceased or incapacitated before adding a representative.');
        }

        $email = mb_strtolower(trim($data['email']));
        if ($email === mb_strtolower(trim((string) $owner->email))) {
            abort(422, "The representative's email must differ from the requestor's own address.");
        }

        // Cheap early exit before any file is written; the unique index on
        // active_request_id remains the guarantee under concurrent adds.
        if (RequestRepresentative::where('active_request_id', $documentRequest->request_id)->exists()) {
            abort(409, 'This request already has an active representative. Revoke it first.');
        }

        foreach (RequestRepresentative::REQUIRED_PROOFS as $kind) {
            if (! isset($files[$kind])) {
                abort(422, "Missing required proof: {$kind}.");
            }
        }

        // Write the files first, row second; if the row cannot be written the
        // files are removed again so no orphaned ID scan is left behind.
        $stored = [];
        try {
            foreach (RequestRepresentative::REQUIRED_PROOFS as $kind) {
                $stored[] = $this->storeProof($documentRequest->request_id, $kind, $files[$kind]);
            }

            $representative = DB::transaction(function () use ($documentRequest, $data, $email, $stored, $actor) {
                return RequestRepresentative::create([
                    'request_id'        => $documentRequest->request_id,
                    'full_name'         => trim($data['full_name']),
                    'email'             => $email,
                    'relationship'      => $data['relationship'],
                    'proofs'            => $stored,
                    'status'            => RequestRepresentative::STATUS_PENDING,
                    'active_request_id' => $documentRequest->request_id,
                    'created_by'        => $actor->user_id,
                ]);
            });
        } catch (UniqueConstraintViolationException) {
            $this->deleteStored($stored);
            abort(409, 'This request already has an active representative. Revoke it first.');
        } catch (\Throwable $e) {
            $this->deleteStored($stored);
            throw $e;
        }

        $this->audit($request, $actor, AuditLog::ACTION_REPRESENTATIVE_ADDED, $representative, [
            'relationship' => $representative->relationship,
        ]);

        return $representative;
    }

    public function verify(RequestRepresentative $representative, SystemUser $actor, Request $request): RequestRepresentative
    {
        DB::transaction(function () use (&$representative, $actor) {
            $representative = RequestRepresentative::whereKey($representative->id)->lockForUpdate()->firstOrFail();

            if ($representative->status !== RequestRepresentative::STATUS_PENDING) {
                abort(409, 'Only a pending representative can be verified.');
            }

            if (config('notification_mail.representative.require_distinct_verifier', true)
                && (int) $representative->created_by === (int) $actor->user_id) {
                abort(403, 'A representative must be verified by someone other than the person who added it.');
            }

            if (! $representative->hasAllRequiredProofs()) {
                abort(422, 'The representative is missing required proof documents.');
            }

            $ownerId = DocumentRequest::withArchived()->whereKey($representative->request_id)->value('user_id');
            if (! $ownerId || ! RequestorStatusFlag::active()->where('user_id', $ownerId)->exists()) {
                abort(422, 'The requestor is no longer marked deceased or incapacitated.');
            }

            $representative->update([
                'status'      => RequestRepresentative::STATUS_VERIFIED,
                'verified_by' => $actor->user_id,
                'verified_at' => now(),
            ]);
        });

        $this->audit($request, $actor, AuditLog::ACTION_REPRESENTATIVE_VERIFIED, $representative);

        return $representative;
    }

    public function revoke(RequestRepresentative $representative, SystemUser $actor, string $reason, Request $request): RequestRepresentative
    {
        DB::transaction(function () use (&$representative, $actor, $reason) {
            $representative = RequestRepresentative::whereKey($representative->id)->lockForUpdate()->firstOrFail();

            if ($representative->status === RequestRepresentative::STATUS_REVOKED) {
                abort(409, 'This representative is already revoked.');
            }

            // Freeing active_request_id lets a corrected entry be added.
            // Proof files are kept: they are the evidence behind the audit trail.
            $representative->update([
                'status'            => RequestRepresentative::STATUS_REVOKED,
                'active_request_id' => null,
                'revoked_by'        => $actor->user_id,
                'revoked_at'        => now(),
                'revoke_reason'     => $reason,
            ]);
        });

        $this->audit($request, $actor, AuditLog::ACTION_REPRESENTATIVE_REVOKED, $representative);

        return $representative;
    }

    /**
     * Decrypts one proof for the reviewing Super Admin. Every view is audited.
     *
     * @return array{content:string,mime:string,filename:string}
     */
    public function proofContents(RequestRepresentative $representative, string $kind, SystemUser $actor, Request $request): array
    {
        $proof = $representative->proof($kind);
        if ($proof === null) {
            abort(404, 'No such proof document.');
        }

        $disk = $this->disk($proof['disk'] ?? null);
        $raw  = $disk->exists($proof['path']) ? $disk->get($proof['path']) : null;
        if ($raw === null) {
            abort(404, 'The proof document is no longer available.');
        }

        $content = Crypt::decryptString($raw);

        $this->audit($request, $actor, AuditLog::ACTION_REPRESENTATIVE_PROOF_VIEWED, $representative, ['kind' => $kind]);

        return [
            'content'  => $content,
            'mime'     => (string) $proof['mime'],
            'filename' => "{$kind}-{$representative->id}." . $this->extensionFor((string) $proof['mime']),
        ];
    }

    /** The verified representative who may receive mail for this request, if any. */
    public function verifiedFor(?int $requestId): ?RequestRepresentative
    {
        if ($requestId === null) {
            return null;
        }

        return RequestRepresentative::verified()->where('request_id', $requestId)->first();
    }

    // ------------------------------------------------------------------

    /** @return array{kind:string,disk:string,path:string,mime:string,size:int,sha256:string} */
    private function storeProof(int $requestId, string $kind, UploadedFile $file): array
    {
        $diskName = (string) config('notification_mail.representative.proof_disk', 'local');
        $plain    = (string) file_get_contents($file->getRealPath());
        // Client-supplied names and mime types are never trusted: the type is
        // sniffed from the bytes, and the stored name is random.
        $mime = (string) ($file->getMimeType() ?: 'application/octet-stream');
        $path = "representative-proofs/{$requestId}/" . Str::uuid() . '.bin';

        $this->disk($diskName)->put($path, Crypt::encryptString($plain));

        return [
            'kind'   => $kind,
            'disk'   => $diskName,
            'path'   => $path,
            'mime'   => $mime,
            'size'   => strlen($plain),
            'sha256' => hash('sha256', $plain),
        ];
    }

    /** @param list<array<string,mixed>> $stored */
    private function deleteStored(array $stored): void
    {
        foreach ($stored as $proof) {
            try {
                $this->disk($proof['disk'])->delete($proof['path']);
            } catch (\Throwable) {
                // best effort: an undeletable file must not mask the real error
            }
        }
    }

    private function disk(?string $name): Filesystem
    {
        $name = $name ?: (string) config('notification_mail.representative.proof_disk', 'local');

        // Proofs are identity documents: refuse a publicly served disk outright.
        if ($name === 'public') {
            abort(500, 'The representative proof disk must be private.');
        }

        return Storage::disk($name);
    }

    private function extensionFor(string $mime): string
    {
        return match ($mime) {
            'application/pdf' => 'pdf',
            'image/jpeg'      => 'jpg',
            'image/png'       => 'png',
            default           => 'bin',
        };
    }

    /** @param array<string,mixed> $extra */
    private function audit(Request $request, SystemUser $actor, string $action, RequestRepresentative $representative, array $extra = []): void
    {
        $ownerId = DocumentRequest::withArchived()->whereKey($representative->request_id)->value('user_id');

        $this->auditLogger->log($request, $actor, $action, $extra + [
            'representative_id' => $representative->id,
            'request_id'        => $representative->request_id,
            'target_user_id'    => $ownerId,
        ]);
    }
}
