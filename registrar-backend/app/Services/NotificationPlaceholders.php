<?php

namespace App\Services;

use App\Models\DocumentRequest;
use Illuminate\Support\Str;

/**
 * Fills the template placeholders that callers should not have to supply
 * themselves: [document_name], [or_number] and [announcement_details]
 * (Policy §3.4). Lookups run only when the template actually uses the token
 * and the caller has not already provided it, so existing call sites keep
 * working and there is no extra query for templates that do not need them.
 */
final class NotificationPlaceholders
{
    private const ANNOUNCEMENT_DETAILS_LIMIT = 300;

    /**
     * @param  array<string,mixed> $data
     * @return array<string,mixed> $data plus any resolved placeholders
     */
    public static function enrich(string $template, array $data, ?int $requestId): array
    {
        $requestId ??= isset($data['request_id']) ? (int) $data['request_id'] : null;

        if (str_contains($template, ':document_name') && ! isset($data['document_name'])) {
            [$names, $plural] = RequestDocumentNames::forRequest($requestId);
            $data['document_name'] = $names;
            $data['document_noun'] ??= $plural ? 'documents' : 'document';
        }

        if (str_contains($template, ':or_number') && ! isset($data['or_number'])) {
            $or = $requestId !== null
                ? DocumentRequest::withArchived()->whereKey($requestId)->value('or_number')
                : null;
            // Free and admin-assisted requests have no OR.
            $data['or_number'] = filled($or) ? $or : 'N/A';
        }

        if (str_contains($template, ':announcement_details')
            && ! isset($data['announcement_details'])
            && isset($data['announcement_content'])) {
            $data['announcement_details'] = Str::limit(
                trim(preg_replace('/\s+/', ' ', strip_tags((string) $data['announcement_content']))),
                self::ANNOUNCEMENT_DETAILS_LIMIT,
            );
        }

        return $data;
    }
}
