<?php

namespace App\Services;

use App\Exceptions\ArchivedCertificationTypesException;
use App\Models\AuditLog;
use App\Models\CertificationType;
use App\Models\SystemUser;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Certificate layout writes that span more than one certification type.
 *
 * Kept out of the controller so the all-or-nothing guarantee lives in one
 * place: every row is locked, checked for archived state, updated and
 * audited inside a SINGLE transaction. Any failure — an archived row found
 * after the lock, a DB error, even the audit write — rolls back every
 * layout change AND the audit row, so the audit log can never claim a
 * change that did not happen.
 */
class CertificationLayoutService
{
    /** Layout columns a bulk item may carry (mirrors UpdateCertificationLayoutRequest). */
    public const LAYOUT_FIELDS = [
        'layout_header_left_url',
        'layout_header_right_url',
        'layout_footer_urls',
        'layout_header_logo_size',
        'layout_footer_logo_size',
        'layout_header_lines',
        'layout_header_font_size',
    ];

    public function __construct(private readonly AuditLogger $auditLogger) {}

    /**
     * @param  array<int, array<string, mixed>>  $items  validated items, each with certificate_type_id
     * @return Collection<int, CertificationType>  fresh rows, in request order
     *
     * @throws ArchivedCertificationTypesException
     */
    public function bulkUpdate(Request $request, SystemUser $actor, array $items): Collection
    {
        $ids = array_map(fn (array $item) => (int) $item['certificate_type_id'], $items);

        return DB::transaction(function () use ($request, $actor, $items, $ids) {
            // Lock in id order (deterministic -> no deadlocks between two
            // concurrent bulk updates) and re-check archived state AFTER the
            // lock, so a concurrent archive() cannot slip in between the
            // check and the write.
            $certs = CertificationType::query()
                ->whereIn('certificate_type_id', $ids)
                ->orderBy('certificate_type_id')
                ->lockForUpdate()
                ->get()
                ->keyBy('certificate_type_id');

            $archived = $certs->filter(fn (CertificationType $c) => $c->is_archived)->keys()->map(fn ($id) => (int) $id)->all();
            if ($archived !== []) {
                throw new ArchivedCertificationTypesException($archived);
            }

            $changed = [];

            foreach ($items as $item) {
                $id = (int) $item['certificate_type_id'];
                $attributes = array_intersect_key($item, array_flip(self::LAYOUT_FIELDS));

                // Same normalisation as the single-item updateLayout().
                if (array_key_exists('layout_footer_urls', $attributes) && $attributes['layout_footer_urls'] === null) {
                    $attributes['layout_footer_urls'] = [];
                }

                $certs[$id]->update($attributes);
                $changed[$id] = array_keys($attributes);
            }

            $this->auditLogger->log($request, $actor, AuditLog::ACTION_CERTIFICATE_LAYOUTS_BULK_UPDATED, [
                'certificate_type_ids' => $ids,
                'count'                => count($ids),
                'changed_fields'       => $changed, // field NAMES only, never values
            ]);

            return CertificationType::query()
                ->whereIn('certificate_type_id', $ids)
                ->get()
                ->sortBy(fn (CertificationType $c) => array_search($c->certificate_type_id, $ids, true))
                ->values();
        });
    }
}
