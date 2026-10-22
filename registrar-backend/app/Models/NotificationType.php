<?php

namespace App\Models;

use App\Enums\NotificationAudienceEnum;
use Illuminate\Database\Eloquent\Model;

class NotificationType extends Model
{
    protected $table      = 'notification_types';
    protected $primaryKey = 'notification_type_id';
    public    $timestamps = true;

    protected $fillable = [
        'trigger_event',
        'title',
        'message_template',
        'audience',
        'is_active',
        'send_push',
    ];

    // Casting to the backed enum means $notificationType->audience is a
    // NotificationAudienceEnum instance everywhere the model is read (e.g.
    // admin UI, API resources), instead of a raw string that has to be
    // trusted to already be valid.
    protected $casts = [
        'audience'  => NotificationAudienceEnum::class,
        'is_active' => 'boolean',
        'send_push' => 'boolean',
    ];

    // All notifications sent using this type
    public function notifications()
    {
        return $this->hasMany(Notification::class, 'notification_type_id');
    }
}