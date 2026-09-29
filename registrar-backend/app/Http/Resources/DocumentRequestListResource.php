<?php

namespace App\Http\Resources;

use App\Models\DocumentRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Arr;

/**
 * Row shape for the staff dashboard list (GET /document-requests) and the
 * logbook (GET /document-requests/logbook).
 *
 * Two jobs:
 *
 *  1. Privacy. The full undergrad_requestor_profile is never part of a list
 *     row — its phone, present_address, date_of_birth and
 *     reason_for_non_enrollment are encrypted at rest for a reason, and a
 *     list has no need for them. Only UNDERGRAD_LIST_FIELDS survive; the
 *     complete profile is available from show() to authorized viewers.
 *     (The controller also restricts the eager-load to these columns, so
 *     the database never returns the encrypted ones for a list; this
 *     whitelist is the second layer in case a caller loads the whole
 *     relation.)
 *
 *  2. One place decides how a requester is displayed. display_name,
 *     student_number and requester_type are computed here for every
 *     account type, so the frontend no longer has to know every profile
 *     shape (that gap is why Undergrad Requestors showed as "N/A").
 *
 * Everything else is the model's normal toArray() output, so existing
 * frontend field reads (status, documents, certificates, release_groups,
 * open_deficiency_notice, ...) keep working unchanged. Additive keys only.
 *
 * @property DocumentRequest $resource
 */
class DocumentRequestListResource extends JsonResource
{
    /** Plaintext identity columns only. Keep in sync with DocumentRequestController::UNDERGRAD_LIST_RELATION. */
    public const UNDERGRAD_LIST_FIELDS = [
        'undergrad_requestor_profile_id',
        'user_id',
        'first_name',
        'middle_name',
        'last_name',
        'suffix',
        'student_number',
        'program',
    ];

    public const TYPE_STUDENT   = 'Student';
    public const TYPE_ALUMNI    = 'Alumni';
    public const TYPE_UNDERGRAD = 'Undergrad Requestor';

    public function toArray(Request $request): array
    {
        $data = $this->resource->toArray();

        if (isset($data['undergrad_requestor_profile']) && is_array($data['undergrad_requestor_profile'])) {
            $data['undergrad_requestor_profile'] = Arr::only(
                $data['undergrad_requestor_profile'],
                self::UNDERGRAD_LIST_FIELDS
            );
        }

        $profile = $this->resolveProfile();

        return array_merge($data, [
            'requester_type' => $this->requesterType(),
            'display_name'   => $profile ? $this->fullName($profile) : null,
            'student_number' => $this->studentNumber($profile),
        ], $this->progress());
    }

    /**
     * Lean, flat summary of the request for places that show it beside ONE
     * item (per-document dashboard rows, the claim screen) — none of the
     * nested relations, so a page of items doesn't repeat every request's
     * full document list in each row. Relations it reads (status, profiles,
     * documents, certificates) should be eager-loaded by the caller.
     */
    public function summary(): array
    {
        $profile = $this->resolveProfile();
        $r       = $this->resource;

        return array_merge([
            'request_id'     => $r->request_id,
            'uuid'           => $r->uuid,
            'requested_at'   => $r->requested_at,
            'status_id'      => $r->status_id,
            'status'         => $r->relationLoaded('status') ? $r->status?->status_name : null,
            'is_archived'    => (bool) $r->is_archived,
            'requester_type' => $this->requesterType(),
            'display_name'   => $profile ? $this->fullName($profile) : null,
            'student_number' => $this->studentNumber($profile),
        ], $this->progress());
    }

    /**
     * Progress badge ("1 of 2 Completed"). Counts come from the already
     * loaded documents/certificates, so this adds no queries; null when
     * neither relation was loaded rather than a misleading 0.
     */
    private function progress(): array
    {
        $r = $this->resource;

        if (!$r->relationLoaded('documents') && !$r->relationLoaded('certificates')) {
            return ['items_total' => null, 'items_completed' => null];
        }

        $items = collect();
        if ($r->relationLoaded('documents')) {
            $items = $items->merge($r->documents);
        }
        if ($r->relationLoaded('certificates')) {
            $items = $items->merge($r->certificates);
        }

        return [
            'items_total'     => $items->count(),
            'items_completed' => $items->where('status_id', \App\Enums\RequestStatusEnum::Completed->value)->count(),
        ];
    }

    private function requesterType(): ?string
    {
        $r = $this->resource;

        return match (true) {
            $r->student_profile_id !== null => self::TYPE_STUDENT,
            $r->alumni_profile_id  !== null => self::TYPE_ALUMNI,
            $r->relationLoaded('undergradRequestorProfile')
                && $r->undergradRequestorProfile !== null => self::TYPE_UNDERGRAD,
            default => null,
        };
    }

    /** The profile row that carries this request's requester name. */
    private function resolveProfile(): mixed
    {
        $r = $this->resource;

        return match ($this->requesterType()) {
            self::TYPE_STUDENT   => $r->relationLoaded('studentProfile') ? $r->studentProfile : null,
            self::TYPE_ALUMNI    => $r->relationLoaded('alumniProfile') ? $r->alumniProfile : null,
            self::TYPE_UNDERGRAD => $r->undergradRequestorProfile,
            default              => null,
        };
    }

    private function studentNumber(mixed $profile): ?string
    {
        $r = $this->resource;

        return match ($this->requesterType()) {
            self::TYPE_STUDENT   => $r->relationLoaded('academicRecord') ? $r->academicRecord?->student_number : null,
            self::TYPE_ALUMNI    => $r->relationLoaded('alumniAcademicRecord') ? $r->alumniAcademicRecord?->student_number : null,
            self::TYPE_UNDERGRAD => $profile?->student_number,
            default              => null,
        };
    }

    private function fullName(mixed $profile): ?string
    {
        $parts = array_filter(
            [$profile->first_name, $profile->middle_name, $profile->last_name, $profile->suffix],
            fn ($part) => is_string($part) && trim($part) !== ''
        );

        return $parts === [] ? null : implode(' ', array_map('trim', $parts));
    }
}
