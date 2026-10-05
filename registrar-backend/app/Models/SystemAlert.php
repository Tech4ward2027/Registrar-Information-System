<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One detected incident. Created only through SystemAlertService::raise(),
 * which relies on the UNIQUE alert_key for atomic de-duplication.
 * `context` may hold user IDs, never names or emails.
 */
class SystemAlert extends Model
{
    protected $table      = 'system_alerts';
    protected $primaryKey = 'system_alert_id';
    public $timestamps    = false;

    public const TYPE_COUNT_SPIKE    = 'count_spike';
    public const TYPE_RATE_SPIKE     = 'rate_spike';
    public const TYPE_REPEAT_FAILURE = 'repeat_failure';

    public const SEVERITY_WARNING  = 'warning';
    public const SEVERITY_CRITICAL = 'critical';

    public const STATUS_OPEN         = 'open';
    public const STATUS_ACKNOWLEDGED = 'acknowledged';
    public const STATUS_RESOLVED     = 'resolved';

    protected $fillable = [
        'alert_key', 'type', 'severity', 'source', 'metric', 'dimension',
        'window_start', 'window_end', 'observed', 'baseline_median',
        'baseline_mad', 'score', 'status', 'acknowledged_by',
        'acknowledged_at', 'context', 'created_at',
    ];

    protected $casts = [
        'window_start'    => 'date',
        'window_end'      => 'date',
        'observed'        => 'float',
        'baseline_median' => 'float',
        'baseline_mad'    => 'float',
        'score'           => 'float',
        'acknowledged_at' => 'datetime',
        'created_at'      => 'datetime',
        'context'         => 'array',
    ];
}
