<?php

namespace App\Http\Controllers;

use App\Http\Requests\UnmatchedCashierItem\ResolveUnmatchedCashierItemRequest;
use App\Models\AuditLog;
use App\Models\CertificationType;
use App\Models\DocumentType;
use App\Models\SystemUser;
use App\Models\UnmatchedCashierItem;
use App\Services\AuditLogger;
use App\Services\CashierLabelNormalizer;
use App\Services\CashierPatternConflictChecker;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Admin screen backing the "close the naming-drift loop" workflow — see
 * CashierDocumentSuggester's class docblock and the
 * unmatched_cashier_items migration for the full rationale. Every receipt
 * label the suggester couldn't match lands here; an admin attaches it to
 * the correct type in one action, and every future receipt using that
 * label auto-matches from then on. No code deploy required.
 */
class UnmatchedCashierItemController extends Controller
{
    public function __construct(
        private AuditLogger $auditLogger,
        private CashierPatternConflictChecker $conflictChecker,
    ) {}

    /**
     * List unmatched items. Defaults to unresolved-only, most-frequent
     * first — that ordering surfaces the highest-value labels to fix
     * first (a label seen 40 times is blocking more students than one
     * seen once). Pass ?resolved=1 to review resolution history instead.
     */
    public function index(Request $request)
    {
        $query = UnmatchedCashierItem::query();

        if ($request->boolean('resolved')) {
            // first_name/last_name live on admin_profile, not on the users
            // table itself (users only has user_id, email, role_id, etc.)
            // — constraining the eager-load to columns that don't exist on
            // `users` throws a SQL error, not an empty result, which is
            // why this branch specifically 500'd. Load the profile
            // relation instead of pretending users carries a name.
            $query->whereNotNull('resolved_at')->with([
                'resolvedByUser:user_id,email',
                'resolvedByUser.adminProfile:admin_profile_id,user_id,first_name,last_name',
            ]);
        } else {
            $query->whereNull('resolved_at');
        }

        $items = $query
            ->orderByDesc('occurrence_count')
            ->orderByDesc('last_seen_at')
            ->paginate($request->integer('per_page', 25));

        return response()->json($items, 200);
    }

    /**
     * Attach this item's raw label to a document/certificate type's
     * cashier_document_patterns, so the suggester matches it going
     * forward, and mark the item resolved.
     *
     * Appends, never replaces — cashier_document_patterns may already
     * hold other valid labels for this type, and a resolve action here
     * must never silently drop them. Dedupes on the same normalised form
     * CashierDocumentSuggester itself uses, so attaching a label that's
     * (post-normalisation) already present is a harmless no-op rather
     * than a literal-duplicate array entry.
     *
     * Cross-type conflict check (added alongside the admin-typed pattern
     * feature on DocumentType/CertificationType create & edit): this is
     * the OTHER place a pattern gets attached to a type, so it's held to
     * the same invariant — App\Rules\CashierPatternsAreConflictFree
     * enforces "a label belongs to at most one type" on the create/edit
     * forms, and without checking here too, an admin could recreate the
     * exact same conflict by resolving a queue item onto a type that
     * doesn't currently surface in CashierDocumentSuggester::
     * buildPatternIndex() (e.g. an archived type, or one outside the
     * visible access ids) but still holds the pattern in its own
     * cashier_document_patterns column.
     */
    public function resolve(ResolveUnmatchedCashierItemRequest $request, $id)
    {
        $item = UnmatchedCashierItem::find($id);
        if (!$item) {
            return response()->json(['message' => 'Unmatched cashier item not found'], 404);
        }

        if ($item->resolved_at) {
            return response()->json(['message' => 'This item has already been resolved.'], 409);
        }

        $validated = $request->validated();

        /** @var SystemUser $actor */
        $actor = Auth::user();

        $isDocument = filled($validated['document_type_id'] ?? null);
        $target = $isDocument
            ? DocumentType::find($validated['document_type_id'])
            : CertificationType::find($validated['certificate_type_id']);

        if (!$target) {
            return response()->json(['message' => 'Target document/certificate type not found'], 404);
        }

        $normalisedLabel = CashierLabelNormalizer::normalize($item->raw_label);

        $conflicts = $this->conflictChecker->findConflicts(
            [$normalisedLabel],
            $isDocument ? 'document' : 'certificate',
            (int) $target->getKey(),
        );

        if (!empty($conflicts)) {
            $conflictingTypeName = reset($conflicts);

            return response()->json([
                'message' => "\"{$item->raw_label}\" is already registered as a cashier match for "
                    . "\"{$conflictingTypeName}\". A cashier label can only be linked to one "
                    . 'document/certificate type — remove it from the other one first.',
            ], 422);
        }

        DB::transaction(function () use ($item, $target, $actor) {
            $patterns = $target->cashier_document_patterns ?? [];

            $alreadyPresent = collect($patterns)->contains(
                fn ($p) => UnmatchedCashierItem::normaliseLabel((string) $p) === $item->normalised_label
            );

            if (!$alreadyPresent) {
                $patterns[] = $item->raw_label;
                $target->update(['cashier_document_patterns' => array_values($patterns)]);
            }

            $item->forceFill([
                'resolved_at' => now(),
                'resolved_by' => $actor->user_id,
            ])->save();
        });

        $this->auditLogger->log($request, $actor, AuditLog::ACTION_UNMATCHED_CASHIER_ITEM_RESOLVED, [
            'unmatched_cashier_item_id' => $item->unmatched_cashier_item_id,
            'raw_label'                 => $item->raw_label,
            'occurrence_count'          => $item->occurrence_count,
            'attached_to_type'          => $isDocument ? 'document' : 'certificate',
            'attached_to_id'            => $target->getKey(),
            'attached_to_name'          => $target->document_name ?? $target->certificate_name,
        ]);

        return response()->json($item->fresh(), 200);
    }

    /**
     * Dismiss an unmatched item WITHOUT attaching a pattern — for labels
     * that aren't a real document/certificate at all (e.g. a one-off
     * miscellaneous fee line on a receipt) and shouldn't clutter the
     * review queue, but also shouldn't be silently treated as "handled"
     * the same way a real pattern-attach is. The distinct audit action
     * (ACTION_UNMATCHED_CASHIER_ITEM_DISMISSED vs ...RESOLVED) keeps that
     * distinction visible in the trail.
     */
    public function dismiss(Request $request, $id)
    {
        $item = UnmatchedCashierItem::find($id);
        if (!$item) {
            return response()->json(['message' => 'Unmatched cashier item not found'], 404);
        }

        if ($item->resolved_at) {
            return response()->json(['message' => 'This item has already been resolved.'], 409);
        }

        /** @var SystemUser $actor */
        $actor = Auth::user();

        $item->forceFill([
            'resolved_at' => now(),
            'resolved_by' => $actor->user_id,
        ])->save();

        $this->auditLogger->log($request, $actor, AuditLog::ACTION_UNMATCHED_CASHIER_ITEM_DISMISSED, [
            'unmatched_cashier_item_id' => $item->unmatched_cashier_item_id,
            'raw_label'                 => $item->raw_label,
            'occurrence_count'          => $item->occurrence_count,
        ]);

        return response()->json($item->fresh(), 200);
    }
}