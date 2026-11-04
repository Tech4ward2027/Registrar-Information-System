<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A requestor account marked deceased or permanently incapacitated
 * (Notification Policy §3.6). Active while lifted_at is null.
 */
class RequestorStatusFlag extends Model
{
    public const STATUS_DECEASED      = 'deceased';
    public const STATUS_INCAPACITATED = 'incapacitated';

    public const STATUSES = [self::STATUS_DECEASED, self::STATUS_INCAPACITATED];

    protected $fillable = [
        'user_id', 'status', 'note', 'flagged_by', 'lifted_at', 'lifted_by', 'lift_reason',
    ];

    protected $casts = [
        'lifted_at' => 'datetime',
    ];

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('lifted_at');
    }

    public function isActive(): bool
    {
        return $this->lifted_at === null;
    }

    public function user()
    {
        return $this->belongsTo(SystemUser::class, 'user_id', 'user_id');
    }
}
