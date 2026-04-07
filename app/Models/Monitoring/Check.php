<?php

namespace App\Models\Monitoring;

use Spatie\ServerMonitor\Models\Check as SpatieCheck;

class Check extends SpatieCheck
{
    protected $table = 'fleet_node_checks';

    public function host(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Host::class , 'host_id');
    }
}
