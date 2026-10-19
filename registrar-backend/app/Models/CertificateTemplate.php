<?php

namespace App\Models;

use App\Enums\CertificateOrientationEnum;
use App\Enums\CertificatePaperSizeEnum;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * One immutable VERSION of a certificate type's template.
 *
 * Everything except the active flag is write-once: a change to content or
 * render settings is a NEW row (see CertificateTemplateService), which is how
 * the version history and "which version produced this certificate" stay
 * trustworthy.
 */
class CertificateTemplate extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'certificate_template';
    protected $primaryKey = 'certificate_template_id';

    protected $fillable = [
        'certificate_type_id', 'version', 'template_slug', 'storage_path', 'content_sha256',
        'paper_size', 'orientation', 'margin_top', 'margin_right', 'margin_bottom', 'margin_left',
        'required_fields', 'is_active', 'change_note', 'created_by',
    ];

    protected $casts = [
        'version'         => 'integer',
        'required_fields' => 'array',
        'is_active'       => 'boolean',
        'margin_top'      => 'float',
        'margin_right'    => 'float',
        'margin_bottom'   => 'float',
        'margin_left'     => 'float',
        'paper_size'      => CertificatePaperSizeEnum::class,
        'orientation'     => CertificateOrientationEnum::class,
        'created_at'      => 'datetime',
    ];

    /** Columns that may change after the row is created. */
    private const MUTABLE = ['is_active', 'active_certificate_type_id'];

    protected static function booted(): void
    {
        // Keep the DB-level "one active per type" column in lockstep with the flag.
        static::saving(function (self $template) {
            $template->active_certificate_type_id = $template->is_active
                ? $template->certificate_type_id
                : null;
        });

        static::updating(function (self $template) {
            $illegal = array_diff(array_keys($template->getDirty()), self::MUTABLE);
            if ($illegal !== []) {
                throw new RuntimeException(
                    'Certificate template versions are immutable; create a new version instead. Attempted: '
                    . implode(', ', $illegal)
                );
            }
        });
    }

    public function certificationType()
    {
        return $this->belongsTo(CertificationType::class, 'certificate_type_id', 'certificate_type_id');
    }

    /** @return array{top: float, right: float, bottom: float, left: float} millimetres */
    public function margins(): array
    {
        return [
            'top'    => $this->margin_top,
            'right'  => $this->margin_right,
            'bottom' => $this->margin_bottom,
            'left'   => $this->margin_left,
        ];
    }
}
