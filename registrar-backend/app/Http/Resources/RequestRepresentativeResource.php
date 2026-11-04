<?php

namespace App\Http\Resources;

use App\Models\SystemUser;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A request's representative as the API shows it.
 *
 * The full email is shown to Super Admin only (they compare it with the ID
 * during verification). Staff see it masked. Proof file paths and hashes are
 * never exposed: documents are read through the audited proof endpoint.
 *
 * @mixin \App\Models\RequestRepresentative
 */
class RequestRepresentativeResource extends JsonResource
{
    public function toArray($request): array
    {
        /** @var SystemUser|null $viewer */
        $viewer = $request->user();
        $isSuperAdmin = $viewer?->isSuperAdmin() ?? false;

        return [
            'id'           => $this->id,
            'request_id'   => $this->request_id,
            'status'       => $this->status,
            'relationship' => $this->relationship,
            'full_name'    => $this->full_name,
            'email_masked' => self::maskEmail((string) $this->email),
            'email'        => $isSuperAdmin ? $this->email : null,
            'proofs'       => collect((array) $this->proofs)->map(fn ($p) => [
                'kind' => $p['kind'],
                'mime' => $p['mime'],
                'size' => $p['size'],
            ])->values(),
            'created_by'   => $this->created_by,
            'created_at'   => $this->created_at,
            'verified_by'  => $this->verified_by,
            'verified_at'  => $this->verified_at,
            'revoked_by'   => $this->revoked_by,
            'revoked_at'   => $this->revoked_at,
            'revoke_reason' => $this->revoke_reason,
        ];
    }

    public static function maskEmail(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');
        if ($domain === '') {
            return '***';
        }

        return mb_substr($local, 0, 1) . '***@' . $domain;
    }
}
