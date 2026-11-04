<?php

namespace App\Services\Tickets;

use App\Enums\TicketSenderTypeEnum;
use App\Models\AuditLog;
use App\Models\SystemUser;
use App\Models\Ticket;
use App\Models\TicketBlock;
use App\Models\TicketCategory;
use App\Models\TicketEvent;
use App\Services\AuditLogger;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Support Ticketing — Phase 8. The management actions behind the tickets
 * "Manage" permission: categories, the spam blocklist and flagging spam.
 *
 * Authorization is the callers' job (the Form Requests check
 * TicketPolicy::manage). Every change is written to the tamper-evident audit
 * log in the SAME transaction as the change itself, so there is never a change
 * without its audit row or an audit row for a change that rolled back.
 *
 * Audit metadata never holds a requester's personal data. A blocked email or IP
 * is recorded as the first 16 characters of its SHA-256, enough to match an
 * entry to a later complaint without keeping the address in a permanent log.
 */
final class TicketAdminService
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly TicketEventRecorder $events,
    ) {
    }

    // =====================================================================
    // CATEGORIES
    // =====================================================================

    /**
     * Codes are never reused or changed, and categories are never deleted
     * (tickets keep pointing at them): deactivate instead.
     *
     * @param  array{code:string,name:string,description?:?string,priority:string,is_active?:bool,sort_order?:int}  $data
     */
    public function createCategory(Request $request, SystemUser $actor, array $data): TicketCategory
    {
        return DB::transaction(function () use ($request, $actor, $data) {
            $category = TicketCategory::create([
                'code'        => $data['code'],
                'name'        => trim($data['name']),
                'description' => isset($data['description']) ? trim($data['description']) : null,
                'priority'    => $data['priority'],
                'is_active'   => $data['is_active'] ?? true,
                'sort_order'  => $data['sort_order'] ?? ((int) TicketCategory::query()->max('sort_order')) + 1,
            ]);

            $this->audit->log($request, $actor, AuditLog::ACTION_TICKET_CATEGORY_CREATED, [
                'category_code' => $category->code,
                'priority'      => $category->priority->value,
                'is_active'     => $category->is_active,
            ]);

            return $category;
        });
    }

    /**
     * Change a category. The priority applies to tickets filed AFTER the change
     * (it is copied onto a ticket when it is created), so open tickets keep the
     * priority they were filed with and the SLA clocks already running are not
     * disturbed.
     *
     * The last active category cannot be deactivated: the new-ticket form would
     * have nothing to offer. Checked under a lock on the active rows, so two
     * managers deactivating the last two at once cannot both succeed.
     *
     * @param  array<string,mixed>  $data  any of name, description, priority, is_active, sort_order
     *
     * @throws ValidationException
     */
    public function updateCategory(Request $request, SystemUser $actor, TicketCategory $category, array $data): TicketCategory
    {
        return DB::transaction(function () use ($request, $actor, $category, $data) {
            $activeIds = TicketCategory::query()->where('is_active', true)->lockForUpdate()->pluck('id')->all();

            $locked = TicketCategory::query()->whereKey($category->getKey())->lockForUpdate()->firstOrFail();

            $locked->fill(array_intersect_key($data, array_flip(['name', 'description', 'priority', 'is_active', 'sort_order'])));

            if (! $locked->isDirty()) {
                return $locked;
            }

            if ($locked->isDirty('is_active') && ! $locked->is_active
                && array_values(array_diff($activeIds, [$locked->getKey()])) === []) {
                throw ValidationException::withMessages([
                    'is_active' => ['At least one category must stay active so tickets can still be filed.'],
                ]);
            }

            $changes = [];
            foreach ($locked->getDirty() as $field => $new) {
                $old = $locked->getOriginal($field);
                $changes[$field] = [$this->plain($old), $this->plain($locked->getAttribute($field))];
            }

            $locked->save();

            $this->audit->log($request, $actor, AuditLog::ACTION_TICKET_CATEGORY_UPDATED, [
                'category_code' => $locked->code,
                'changes'       => $changes,
            ]);

            return $locked;
        });
    }

    // =====================================================================
    // BLOCKLIST
    // =====================================================================

    /**
     * Block an email address or IP from starting public tickets. Adding a value
     * that is already listed renews it (new reason, new expiry) instead of
     * failing, so a manager never has to remove and re-add to extend a block.
     *
     * @param  'email'|'ip'  $type
     */
    public function addBlock(Request $request, SystemUser $actor, string $type, string $value, ?string $reason, ?CarbonInterface $expiresAt): TicketBlock
    {
        return DB::transaction(function () use ($request, $actor, $type, $value, $reason, $expiresAt) {
            $block = $this->upsertBlock($actor, $type, $value, $reason, $expiresAt);

            $this->audit->log($request, $actor, AuditLog::ACTION_TICKET_BLOCK_ADDED, [
                'block_id'     => $block->getKey(),
                'type'         => $block->type,
                'value_sha256' => $this->fingerprint($block->value),
                'expires_at'   => $block->expires_at?->toIso8601String(),
            ]);

            return $block;
        });
    }

    public function removeBlock(Request $request, SystemUser $actor, TicketBlock $block): void
    {
        DB::transaction(function () use ($request, $actor, $block) {
            $this->audit->log($request, $actor, AuditLog::ACTION_TICKET_BLOCK_REMOVED, [
                'block_id'     => $block->getKey(),
                'type'         => $block->type,
                'value_sha256' => $this->fingerprint($block->value),
            ]);

            $block->delete();
        });
    }

    /**
     * Normalise a blocklist value the same way the lookup does
     * (TicketBlockList lower-cases emails and compares IPs as stored), so a
     * block entered in capitals still matches.
     */
    public static function normalise(string $type, string $value): string
    {
        $value = trim($value);

        if ($type === TicketBlock::TYPE_IP) {
            $packed = @inet_pton($value);

            return $packed === false ? $value : (string) inet_ntop($packed);
        }

        return mb_strtolower($value);
    }

    // =====================================================================
    // SPAM
    // =====================================================================

    /**
     * Flag a ticket as spam, and optionally block the address and/or IP it came
     * from. Flagging is a logged fact on the ticket; it does not change the
     * ticket's status, so staff then resolve it like any other ticket.
     *
     * Only a PUBLIC submitter can be blocked (the blocklist guards the public
     * form; a signed-in requester has an account, handled through account
     * administration), and only while their details still exist.
     *
     * The reason lives on the block, not in the ticket's hash-chained log, so it
     * can be removed with the block.
     *
     * @param  list<'email'|'ip'>  $blockTypes
     *
     * @throws ValidationException
     */
    public function flagSpam(Request $request, SystemUser $actor, Ticket $ticket, array $blockTypes, ?string $reason, ?CarbonInterface $blockExpiresAt): Ticket
    {
        return DB::transaction(function () use ($request, $actor, $ticket, $blockTypes, $reason, $blockExpiresAt) {
            $locked = Ticket::query()->whereKey($ticket->getKey())->lockForUpdate()->firstOrFail();

            $blocked = [];

            if ($blockTypes !== []) {
                if ($locked->requester_user_id !== null || $locked->anonymized_at !== null) {
                    throw ValidationException::withMessages([
                        'block' => ['Only a public submitter can be blocked, and only while their details are still on file.'],
                    ]);
                }

                foreach (array_unique($blockTypes) as $type) {
                    $value = $type === TicketBlock::TYPE_EMAIL ? $locked->requester_email : $locked->requester_ip;

                    if (blank($value)) {
                        throw ValidationException::withMessages(['block' => ["This ticket has no {$type} on file to block."]]);
                    }

                    $this->upsertBlock($actor, $type, (string) $value, $reason, $blockExpiresAt);
                    $blocked[] = $type;
                }
            }

            $this->events->record(
                $locked, TicketEvent::TYPE_SPAM_FLAGGED, TicketSenderTypeEnum::Staff, $actor->user_id,
                null, null,
                ['blocked' => $blocked],
            );

            $this->audit->log($request, $actor, AuditLog::ACTION_TICKET_SPAM_FLAGGED, [
                'ticket_number' => $locked->ticket_number,
                'blocked'       => $blocked,
            ]);

            return $locked;
        });
    }

    // =====================================================================
    // INTERNALS
    // =====================================================================

    private function upsertBlock(SystemUser $actor, string $type, string $value, ?string $reason, ?CarbonInterface $expiresAt): TicketBlock
    {
        return TicketBlock::query()->updateOrCreate(
            ['type' => $type, 'value' => self::normalise($type, $value)],
            [
                'reason'             => $reason !== null && trim($reason) !== '' ? trim($reason) : null,
                'expires_at'         => $expiresAt,
                'created_by_user_id' => $actor->user_id,
            ],
        );
    }

    private function fingerprint(string $value): string
    {
        return substr(hash('sha256', $value), 0, 16);
    }

    /** Enums and booleans as plain values, so the audit JSON is readable. */
    private function plain(mixed $value): mixed
    {
        return $value instanceof \BackedEnum ? $value->value : $value;
    }
}
