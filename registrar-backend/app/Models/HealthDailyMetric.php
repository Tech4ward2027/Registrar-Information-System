<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One pre-aggregated daily count (see config/system_health.php for the
 * metric catalog). Counts only; no personal data. Written by the rollup
 * service via idempotent upsert, read by the anomaly detector and the
 * System Health API, pruned by health:prune.
 */
class HealthDailyMetric extends Model
{
    protected $table      = 'health_daily_metrics';
    protected $primaryKey = 'health_daily_metric_id';
    public $timestamps    = false;

    protected $fillable = ['metric_date', 'source', 'metric', 'dimension', 'count', 'computed_at'];

    protected $casts = [
        'metric_date' => 'date',
        'count'       => 'integer',
        'computed_at' => 'datetime',
    ];
}
