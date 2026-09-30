<?php

namespace App\Services;

use App\Models\RequestCertificate;
use App\Models\RequestDocument;
use App\Models\RequestRemark;

/**
 * Phase 5 - the single place that answers "is this item on hold?".
 *
 * An OPEN item-level Deficiency Notice holds exactly its own document or
 * certificate: that item cannot be released (claimed / marked Done). Other
 * items on the same request are unaffected. A request-level notice keeps its
 * existing meaning (a banner, not a claim block) and is deliberately ignored
 * here.
 *
 * Stateless static helpers so every release path (item claim, whole-request
 * claim, legacy release-group claim, manual Done) shares one rule and none of
 * their constructors change.
 */
final class ItemDeficiencyHold
{
    /** Key used to index holds by item: "d:<id>" for documents, "c:<id>" for certificates. */
    public static function key(RequestDocument|RequestCertificate $item): string
    {
        return $item instanceof RequestDocument
            ? 'd:' . $item->request_document_id
            : 'c:' . $item->request_certificate_id;
    }

    /**
     * Open item-level notices of one request (or a list of requests), indexed by item key.
     * One query, so callers can annotate many items without N+1.
     *
     * @return array<string, RequestRemark>
     */
    public static function forRequest(int|array $requestIds, bool $lock = false): array
    {
        $query = RequestRemark::whereIn('request_id', (array) $requestIds)->open()->itemLevel();

        if ($lock) {
            $query->lockForUpdate();
        }

        $holds = [];

        foreach ($query->get() as $remark) {
            $key = $remark->request_document_id !== null
                ? 'd:' . $remark->request_document_id
                : 'c:' . $remark->request_certificate_id;

            $holds[$key] = $remark;
        }

        return $holds;
    }

    public static function forItem(RequestDocument|RequestCertificate $item, bool $lock = false): ?RequestRemark
    {
        $column = $item instanceof RequestDocument ? 'request_document_id' : 'request_certificate_id';

        $query = RequestRemark::where($column, $item->getKey())->open();

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->first();
    }

    /** Short, staff-facing reason (no trailing period - callers add their own). */
    public static function reason(RequestRemark $remark): string
    {
        return 'on hold (Deficiency Notice: ' . ($remark->item_label ?: 'open') . ')';
    }

    /** Aborts 422 if the item has an open notice. Call inside the lock. */
    public static function assertNotHeld(RequestDocument|RequestCertificate $item): void
    {
        $remark = self::forItem($item, lock: true);

        if ($remark !== null) {
            abort(422, 'This item is ' . self::reason($remark) . ' and cannot be released. Clear or void the notice first.');
        }
    }
}
