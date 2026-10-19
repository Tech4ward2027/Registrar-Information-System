<?php

namespace App\Models;

use App\Enums\CertificateGenerationStatusEnum;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * One certificate generation attempt (see the create migration for the why).
 *
 * The audit value of this table depends on rows not being rewritten, so only
 * the outcome columns may change, and only once: pending -> generated|failed.
 */
class CertificateGeneration extends Model
{
    public const UPDATED_AT = null;

    protected $table      = 'certificate_generation';
    protected $primaryKey = 'certificate_generation_id';

    protected $fillable = [
        'control_number', 'certificate_type_id', 'certificate_template_id', 'template_version',
        'requester_user_id', 'student_number', 'request_id', 'request_certificate_id',
        'status', 'failure_code', 'payload_snapshot', 'generated_by', 'generated_at',
    ];

    protected $casts = [
        'template_version' => 'integer',
        'status'           => CertificateGenerationStatusEnum::class,
        'payload_snapshot' => 'array',
        'created_at'       => 'datetime',
        'generated_at'     => 'datetime',
    ];

    private const MUTABLE = ['status', 'failure_code', 'generated_at'];

    protected static function booted(): void
    {
        static::updating(function (self $generation) {
            $illegal = array_diff(array_keys($generation->getDirty()), self::MUTABLE);
            if ($illegal !== []) {
                throw new RuntimeException(
                    'Certificate generation records are immutable. Attempted: ' . implode(', ', $illegal)
                );
            }

            $original = $generation->getOriginal('status');
            $was      = $original instanceof CertificateGenerationStatusEnum
                ? $original
                : CertificateGenerationStatusEnum::from((string) $original);

            if ($was !== CertificateGenerationStatusEnum::Pending) {
                throw new RuntimeException('A finished certificate generation record cannot be changed.');
            }
        });
    }

    public function certificationType()
    {
        return $this->belongsTo(CertificationType::class, 'certificate_type_id', 'certificate_type_id');
    }

    public function template()
    {
        return $this->belongsTo(CertificateTemplate::class, 'certificate_template_id', 'certificate_template_id');
    }
}
