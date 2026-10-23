<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Notification Model
|--------------------------------------------------------------------------
| Represents one notification row in the notifications table.
|
| Key things to understand:
|
| SoftDeletes trait:
|   Adds deleted_at support automatically. When you call $notif->delete(),
|   Laravel sets deleted_at = now() instead of removing the row.
|   All queries automatically exclude soft-deleted rows unless you
|   explicitly call ::withTrashed().
|
| UUID primary key:
|   Our notifications table uses CHAR(36) UUID as primary key, not
|   an auto-increment integer. We tell Laravel this by setting
|   $incrementing = false and $keyType = 'string'.
|   We auto-generate the UUID in the boot() method below.
|
| Casts:
|   The 'data' column is JSON in MySQL. The 'array' cast means Laravel
|   will automatically json_decode() it when you read it, and
|   json_encode() it when you write it. So in PHP you work with
|   a plain array, never raw JSON strings.
|--------------------------------------------------------------------------
*/

class Notification extends Model
{
    use SoftDeletes;

    protected $table      = 'notifications';
    protected $primaryKey = 'id';
    public    $incrementing = false;   // UUID, not auto-increment
    protected $keyType    = 'string';  // UUID is a string
    public    $timestamps = true;

    protected $fillable = [
        'id',
        'notification_type_id',
        'notifiable_type',
        'notifiable_id',
        'data',
        'request_id',
        'read_at',
    ];

    protected $casts = [
        'data'       => 'array',        // auto JSON encode/decode
        'read_at'    => 'datetime',
        'deleted_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    // -------------------------------------------------------
    // AUTO-GENERATE UUID on create
    // -------------------------------------------------------
    // boot() is a Laravel lifecycle hook that runs when the
    // model class is first loaded. 'creating' fires just before
    // a new row is inserted into the DB.
    // Str::uuid() generates a UUID4 string like:
    // "550e8400-e29b-41d4-a716-446655440000"
    // -------------------------------------------------------
    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (Notification $notification) {
            if (empty($notification->id)) {
                $notification->id = (string) Str::uuid();
            }
        });
    }

    // -------------------------------------------------------
    // RELATIONSHIPS
    // -------------------------------------------------------

    // Which notification template was used?
    public function type()
    {
        return $this->belongsTo(NotificationType::class, 'notification_type_id');
    }

    // Which document request triggered this? (nullable)
    public function request()
    {
        return $this->belongsTo(DocumentRequest::class, 'request_id');
    }

    /**
     * Eager-load the request's public_id for NotificationResource, selecting
     * only the two columns needed. Archived requests are included so a
     * notification about an archived request still resolves.
     */
    public function scopeWithRequestPublicId(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        return $query->with([
            'request' => fn ($relation) => $relation->withArchived()->select('request_id', 'public_id'),
        ]);
    }

    // -------------------------------------------------------
    // HELPERS
    // -------------------------------------------------------

    // Is this notification unread?
    public function isUnread(): bool
    {
        return is_null($this->read_at);
    }

    // Mark as read right now
    public function markAsRead(): void
    {
        if (is_null($this->read_at)) {
            $this->update(['read_at' => now()]);
        }
    }
}