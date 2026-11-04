<?php

namespace App\Services;

use App\Models\RequestCertificate;
use App\Models\RequestDocument;

/**
 * Human-readable names of the documents on a request, for notification and
 * email wording ([document_name] in Policy §3.4). Shared by the System Mailer
 * and the scheduled forfeiture notice so in-app, push and email say the same.
 */
final class RequestDocumentNames
{
    private const MAX_NAMES = 5;

    /** @return array{0:string,1:bool} comma-separated names, and whether there is more than one */
    public static function forRequest(?int $requestId): array
    {
        if ($requestId === null) {
            return ['your request', false];
        }

        $names = RequestDocument::where('request_id', $requestId)->with('documentType')->get()
            ->map(fn ($d) => $d->documentType?->document_name)
            ->merge(
                RequestCertificate::where('request_id', $requestId)->with('certificationType')->get()
                    ->map(fn ($c) => $c->certificationType?->certificate_name)
            )
            ->filter()
            ->unique()
            ->values();

        if ($names->isEmpty()) {
            return ['your request', false];
        }

        $total = $names->count();
        $shown = $names->take(self::MAX_NAMES)->implode(', ');
        if ($total > self::MAX_NAMES) {
            $shown .= ' and ' . ($total - self::MAX_NAMES) . ' more';
        }

        return [$shown, $total > 1];
    }
}
