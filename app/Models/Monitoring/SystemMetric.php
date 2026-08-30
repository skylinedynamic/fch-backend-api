<?php

namespace App\Models\Monitoring;

use Illuminate\Database\Eloquent\Model;

class SystemMetric extends Model
{
    // Snapshot table only needs creation timestamp
    const UPDATED_AT = null;

    protected $fillable = [
        'cpu_usage',
        'ram_usage',
        'disk_usage',
        'users_count',
    ];

    protected $casts = [
        'cpu_usage' => 'float',
        'ram_usage' => 'float',
        'disk_usage' => 'float',
        'created_at' => 'datetime',
    ];
}
