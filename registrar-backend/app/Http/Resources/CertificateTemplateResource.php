<?php

namespace App\Http\Resources;

use App\Models\CertificateTemplate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Template METADATA. Deliberately omits storage_path (an internal object key)
 * and never carries the body — the controller adds `content` only on the
 * single-template read.
 *
 * @mixin CertificateTemplate
 */
class CertificateTemplateResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'certificate_template_id' => $this->certificate_template_id,
            'certificate_type_id'     => $this->certificate_type_id,
            'version'                 => $this->version,
            'template_slug'           => $this->template_slug,
            'is_active'               => $this->is_active,
            'paper_size'              => $this->paper_size->value,
            'orientation'             => $this->orientation->value,
            'margins'                 => $this->margins(),
            'required_fields'         => $this->required_fields ?? [],
            'content_sha256'          => $this->content_sha256,
            'change_note'             => $this->change_note,
            'created_by'              => $this->created_by,
            'created_at'              => $this->created_at?->toIso8601String(),
        ];
    }
}
