<?php

namespace App\Models;

use App\Jobs\SuggestUnmatchedLabelTargetJob;
use App\Services\CashierLabelNormalizer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

/**
 * A cashier receipt label that CashierDocumentSuggester could not match to
 * any document/certificate type's cashier_document_patterns. See the
 * suggester's class docblock and the table's own migration for the full
 * rationale — this is the operational, admin-driven fix for receipt-label
 * drift, standing in deliberately for algorithmic fuzzy matching.
 *
 * Phase 4: a newly created row may also carry ranked suggestions
 * (suggestions / suggestion_source / suggested_at), computed off the request
 * path by SuggestUnmatchedLabelTargetJob. They are advisory only — an admin
 * still resolves every item by hand — and suggestion_accepted records, at
 * resolve time, whether the admin chose the top suggestion.
 */
class UnmatchedCashierItem extends Model
{
    protected $table      = 'unmatched_cashier_items';
    protected $primaryKey = 'unmatched_cashier_item_id';

    protected $fillable = [
        'raw_label',
        'normalised_label',
        'occurrence_count',
        'first_seen_at',
        'last_seen_at',
        'resolved_at',
        'resolved_by',
    ];

    // The suggestion_* columns are deliberately NOT mass-assignable: they are
    // written only by the suggestion job (query builder) and by the resolve
    // action (forceFill), never from request input.

    protected $casts = [
        'occurrence_count'    => 'integer',
        'first_seen_at'       => 'datetime',
        'last_seen_at'        => 'datetime',
        'resolved_at'         => 'datetime',
        'suggestions'         => 'array',
        'suggested_at'        => 'datetime',
        'suggestion_accepted' => 'boolean',
    ];

    public function resolvedByUser()
    {
        return $this->belongsTo(SystemUser::class, 'resolved_by', 'user_id');
    }

    /**
     * Record one sighting of a raw label. Upserts on the normalised form:
     * a first sighting creates the row, every later sighting of the same
     * (normalised) label just bumps occurrence_count and last_seen_at.
     *
     * A previously-resolved label that starts appearing again is left
     * resolved — an admin already matched it to a pattern, so the
     * suggester should now be matching it directly; if it's still landing
     * here, the label's dedupe key changed in a way normalise() doesn't
     * account for, which is itself useful signal to leave resolved_at
     * alone and let it surface for review rather than silently reopening.
     *
     * Static normalisation logic is intentionally NOT duplicated here —
     * the caller (CashierDocumentSuggester) already normalised once for
     * its own index lookup, so this method accepts the raw label and
     * re-derives the same normalised form via the shared algorithm to
     * avoid two normalisers ever drifting apart. See normaliseLabel().
     */
    public static function recordSighting(string $rawLabel): void
    {
        $normalised = static::normaliseLabel($rawLabel);

        if ($normalised === '') {
            return;
        }

        $now = now();

        $existing = static::where('normalised_label', $normalised)->first();

        if ($existing) {
            $existing->increment('occurrence_count');
            $existing->forceFill(['last_seen_at' => $now])->save();
            return;
        }

        try {
            $created = static::create([
                'raw_label'        => $rawLabel,
                'normalised_label' => $normalised,
                'occurrence_count' => 1,
                'first_seen_at'    => $now,
                'last_seen_at'     => $now,
            ]);
        } catch (\Illuminate\Database\QueryException $e) {
            // Race: two concurrent submissions saw the same unresolved
            // label at once and both passed the existence check above.
            // The unique index on normalised_label (see migration) makes
            // the loser's insert fail rather than duplicate the row —
            // treat that as "someone else just recorded it" and bump the
            // count instead, rather than surfacing an error to the student
            // over what is purely an internal logging concern.
            if ((string) $e->getCode() === '23000') {
                $row = static::where('normalised_label', $normalised)->first();
                if ($row) {
                    $row->increment('occurrence_count');
                    $row->forceFill(['last_seen_at' => $now])->save();
                }
                return;
            }

            throw $e;
        }

        // Only the request that actually created the row queues suggestions
        // (the race loser above returns early), so there is one job per
        // distinct label.
        static::queueSuggestions($created);
    }

    /**
     * Queue suggestion generation for a newly created row.
     *
     * Behind the ai_label_suggestions flag (default off) and best-effort in
     * the same spirit as CashierDocumentSuggester::recordUnmatched(): a
     * queue outage must never affect the student's OR verification, so any
     * failure is logged and swallowed.
     */
    private static function queueSuggestions(self $item): void
    {
        if (!config('features.ai_label_suggestions', false)) {
            return;
        }

        try {
            SuggestUnmatchedLabelTargetJob::dispatch((int) $item->getKey())->afterCommit();
        } catch (\Throwable $e) {
            Log::warning('UnmatchedCashierItem: could not queue label suggestions', [
                'item_id'   => $item->getKey(),
                'exception' => $e::class,
            ]);
        }
    }

    /**
     * Same normalisation as CashierDocumentSuggester::normalise() and
     * CashierDocumentMatcher::normalise() — delegates to
     * CashierLabelNormalizer, the single source of truth for this
     * algorithm across the cashier subsystem. This model's uniqueness
     * guarantee on normalised_label depends on that staying identical to
     * the suggester's own lookup key, so all three now share one
     * implementation instead of three independently-maintained copies.
     */
    public static function normaliseLabel(string $label): string
    {
        return CashierLabelNormalizer::normalize($label);
    }
}