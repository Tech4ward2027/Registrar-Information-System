<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * One delivery attempt on one channel (Notification Policy §4).
 *
 * Append-only: rows are written by NotificationDispatchLogger and removed only
 * by the retention job (a query-builder mass delete, which does not fire the
 * model events guarded below — same structural rule as SecurityEvent).
 */
class NotificationDispatchLog extends Model
{
    public const CHANNEL_IN_APP = 'in_app';
    public const CHANNEL_PUSH   = 'push';
    public const CHANNEL_MAIL   = 'mail';

    public const STATUS_SENT    = 'sent';     // handed to the channel successfully
    public const STATUS_PARTIAL = 'partial';  // some devices/messages accepted, some not
    public const STATUS_FAILED  = 'failed';   // attempted and failed
    public const STATUS_SKIPPED = 'skipped';  // deliberately not attempted (see reason)

    // Reasons — keep within varchar(64).
    public const REASON_UNKNOWN_TRIGGER   = 'unknown_trigger';
    public const REASON_EXCEPTION         = 'exception';
    public const REASON_NOT_CONFIGURED    = 'not_configured';
    public const REASON_NOTIFICATION_GONE = 'notification_gone_or_read';
    public const REASON_RECIPIENT_INACTIVE = 'recipient_inactive';
    public const REASON_NO_ACTIVE_SESSION = 'no_active_session';
    public const REASON_NO_SUBSCRIPTIONS  = 'no_subscriptions';
    public const REASON_QUEUE_ERROR       = 'queue_error';
    public const REASON_PROVIDER_REJECTED = 'provider_rejected';
    public const REASON_MAIL_NOT_APPROVED = 'mail_not_approved';   // trigger is not on the policy allow-list
    public const REASON_MAIL_DISABLED     = 'mail_disabled';       // NOTIFICATION_MAIL_ENABLED=false
    public const REASON_NO_EMAIL_ADDRESS  = 'no_email_address';
    public const REASON_QUOTA_EXCEEDED    = 'quota_exceeded';
    public const REASON_ALREADY_SENT      = 'already_sent';
    public const REASON_DUPLICATE         = 'duplicate_suppressed';
    // Policy §3.6 (deceased / incapacitated requestor)
    public const REASON_REQUESTOR_FLAGGED   = 'requestor_flagged';        // requestor's own address suppressed
    public const REASON_SENT_ON_FILE        = 'closure_sent_on_file';     // no verified representative; in-app notice is the record
    public const REASON_VIA_REPRESENTATIVE  = 'via_representative';       // delivered to the verified representative, not the requestor

    public $timestamps = false;

    protected $fillable = [
        'channel',
        'status',
        'reason',
        'trigger_event',
        'notification_id',
        'user_id',
        'request_id',
        'attempted',
        'delivered',
        'error',
        'created_at',
    ];

    protected $casts = [
        'attempted'  => 'integer',
        'delivered'  => 'integer',
        'created_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(function () {
            throw new RuntimeException('notification_dispatch_logs is append-only.');
        });

        static::deleting(function () {
            throw new RuntimeException('notification_dispatch_logs rows are removed only by the retention job.');
        });
    }
}