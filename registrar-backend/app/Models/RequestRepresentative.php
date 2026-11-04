<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * The authorised representative or next of kin of a deceased or
 * permanently incapacitated requestor, for one request (Policy §3.6).
 *
 * Only a VERIFIED row ever receives mail. Name and email are encrypted at
 * rest (APP_KEY); proof documents are stored privately and referenced here.
 */
class RequestRepresentative extends Model
{
    public const STATUS_PENDING  = 'pending';
    public const STATUS_VERIFIED = 'verified';
    public const STATUS_REVOKED  = 'revoked';

    public const PROOF_REPRESENTATIVE_ID = 'representative_id';
    public const PROOF_RELATIONSHIP      = 'relationship_proof';
    public const PROOF_EVENT             = 'event_proof';

    /** Every representative needs all three before it can be verified. */
    public const REQUIRED_PROOFS = [
        self::PROOF_REPRESENTATIVE_ID,
        self::PROOF_RELATIONSHIP,
        self::PROOF_EVENT,
    ];

    public const RELATIONSHIPS = [
        'parent', 'child', 'spouse', 'sibling', 'legal_guardian', 'court_appointed', 'other_authorized',
    ];

    protected $fillable = [
        'request_id', 'full_name', 'email', 'relationship', 'proofs', 'status',
        'active_request_id', 'created_by', 'verified_by', 'verified_at',
        'revoked_by', 'revoked_at', 'revoke_reason',
    ];

    protected $casts = [
        'full_name'   => 'encrypted',
        'email'       => 'encrypted',
        'proofs'      => 'array',
        'verified_at' => 'datetime',
        'revoked_at'  => 'datetime',
    ];

    protected $hidden = ['full_name', 'email', 'proofs'];

    public function scopeVerified(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_VERIFIED);
    }

    public function isVerified(): bool
    {
        return $this->status === self::STATUS_VERIFIED;
    }

    public function documentRequest()
    {
        return $this->belongsTo(DocumentRequest::class, 'request_id', 'request_id');
    }

    /** @return array<string,mixed>|null the stored reference for one proof kind */
    public function proof(string $kind): ?array
    {
        foreach ((array) $this->proofs as $proof) {
            if (($proof['kind'] ?? null) === $kind) {
                return $proof;
            }
        }

        return null;
    }

    public function hasAllRequiredProofs(): bool
    {
        foreach (self::REQUIRED_PROOFS as $kind) {
            if ($this->proof($kind) === null) {
                return false;
            }
        }

        return true;
    }
}
