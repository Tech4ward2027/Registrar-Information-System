<?php

namespace App\Services;

use App\Http\Requests\DocumentRequest\LogbookIndexRequest;
use App\Models\DocumentRequest;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Server-side filtering for GET /document-requests/logbook.
 *
 * The logbook used to download every Completed request and filter it in the
 * browser. This class applies the same filters in SQL so the screen can load
 * one page at a time and the record total comes from the paginator.
 *
 * CATEGORY RULE (one place, mirrored by logbookHelpers.js):
 *   A document / certificate line is logged under
 *     - its type's logbook_category name when logbook_category_id is set,
 *     - otherwise the type's own name.
 *   A selected label matches a line only by EXACT (case-insensitive) equality
 *   with that resolved label. There is no substring matching, so a type named
 *   "Doctor's Note" can never be mistaken for "Transcript of Records".
 *
 * All values are bound; nothing from the request is interpolated into SQL.
 */
class LogbookQueryService
{
    /**
     * Relations the logbook needs on top of the staff-list relations: the
     * requester's own account e-mail, so the e-mail column can be resolved
     * for Alumni (and Students) without a second request. Column lists are
     * restricted to what is read.
     */
    public const EMAIL_RELATIONS = [
        'alumniProfile.alumni:alumni_id,user_id',
        'alumniProfile.alumni.user:user_id,email',
        'studentProfile.user:user_id,email',
    ];

    public function apply(Builder $query, LogbookIndexRequest $request): Builder
    {
        $this->applyCompleted($query);
        $this->applyDateRange($query, $request->input('from'), $request->input('to'));
        $this->applyLegacyDocType($query, $request->input('doc_type'));
        $this->applySearch($query, $request->searchTerm());

        if ($request->mode() === LogbookIndexRequest::MODE_CERTIFICATIONS) {
            $this->applyCertificationLabel($query, $request->certificationLabel());
        } else {
            $this->applyDocumentLabels($query, $request->categoryLabels());
        }

        return $query;
    }

    /**
     * The e-mail to show for a request: the requester's own account first
     * (Alumni profile owner, then Student profile owner), then the account
     * the request was filed under. Null when none is on record.
     */
    public function resolveEmail(DocumentRequest $request): ?string
    {
        $candidates = [
            $request->relationLoaded('alumniProfile')
                ? $request->alumniProfile?->alumni?->user?->email
                : null,
            $request->relationLoaded('studentProfile')
                ? $request->studentProfile?->user?->email
                : null,
            $request->relationLoaded('user') ? $request->user?->email : null,
        ];

        foreach ($candidates as $email) {
            if (is_string($email) && trim($email) !== '') {
                return trim($email);
            }
        }

        return null;
    }

    // -------------------------------------------------------------------------
    // Filters
    // -------------------------------------------------------------------------

    private function applyCompleted(Builder $query): void
    {
        $query->whereHas('status', fn (Builder $q) => $q->where('status_name', 'Completed'));
    }

    private function applyDateRange(Builder $query, ?string $from, ?string $to): void
    {
        if ($from) {
            $query->whereDate('requested_at', '>=', $from);
        }

        if ($to) {
            $query->whereDate('requested_at', '<=', $to);
        }
    }

    /** Older callers sent a partial document name. Kept for compatibility. */
    private function applyLegacyDocType(Builder $query, ?string $docType): void
    {
        $docType = trim((string) $docType);

        if ($docType === '') {
            return;
        }

        $query->whereHas('documents.documentType', function ($q) use ($docType) {
            $q->where('document_name', 'like', '%' . DocumentRequest::escapeLike($docType) . '%');
        });
    }

    /**
     * Name / student number / claim code / request id (the model's shared
     * search) plus the two logbook-only fields: OR number and account e-mail
     * (both prefix matches, like the shared search). The logbook searches the
     * requester, not the item names, so item-name matching is switched off.
     */
    private function applySearch(Builder $query, string $term): void
    {
        if ($term === '') {
            return;
        }

        $like = DocumentRequest::escapeLike($term) . '%';

        $query->where(function (Builder $q) use ($term, $like) {
            $q->search($term, false);

            // ESCAPE '!' matches DocumentRequest::escapeLike().
            $q->orWhereRaw("document_request.or_number LIKE ? ESCAPE '!'", [$like]);
            $q->orWhereIn(
                'document_request.user_id',
                DB::table('users')->select('user_id')->whereRaw("users.email LIKE ? ESCAPE '!'", [$like])
            );
        });
    }

    /** @param list<string> $labels  empty = no restriction */
    private function applyDocumentLabels(Builder $query, array $labels): void
    {
        if ($labels === []) {
            return;
        }

        $lower = $this->lowercase($labels);

        $query->whereHas('documents.documentType', function (Builder $type) use ($lower) {
            $this->matchResolvedLabel($type, $lower, 'document_name');
        });
    }

    private function applyCertificationLabel(Builder $query, ?string $label): void
    {
        if ($label === null) {
            // "All Certification": any request that has at least one certificate.
            $query->whereHas('certificates');

            return;
        }

        $lower = $this->lowercase([$label]);

        $query->whereHas('certificates.certificationType', function (Builder $type) use ($lower) {
            $this->matchResolvedLabel($type, $lower, 'certificate_name');
        });
    }

    /**
     * Constrain a document_type / certificate_type query to types whose
     * resolved logbook label is one of $lowerLabels (see class docblock).
     *
     * @param list<string> $lowerLabels
     */
    private function matchResolvedLabel(Builder $type, array $lowerLabels, string $ownNameColumn): void
    {
        $placeholders = implode(',', array_fill(0, count($lowerLabels), '?'));

        $type->where(function (Builder $q) use ($lowerLabels, $placeholders, $ownNameColumn) {
            $q->whereHas('logbookCategory', function (Builder $category) use ($lowerLabels, $placeholders) {
                $category->whereRaw("LOWER(name) IN ({$placeholders})", $lowerLabels);
            })->orWhere(function (Builder $own) use ($lowerLabels, $placeholders, $ownNameColumn) {
                // $ownNameColumn is one of two internal constants, never input.
                $own->whereNull('logbook_category_id')
                    ->whereRaw("LOWER({$ownNameColumn}) IN ({$placeholders})", $lowerLabels);
            });
        });
    }

    /**
     * @param list<string> $values
     * @return list<string>
     */
    private function lowercase(array $values): array
    {
        return array_values(array_map(fn (string $v) => mb_strtolower($v), $values));
    }
}
