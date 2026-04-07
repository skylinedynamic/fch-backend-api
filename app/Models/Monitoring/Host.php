<?php

namespace App\Models\Monitoring;

use Spatie\ServerMonitor\Models\Host as SpatieHost;

class Host extends SpatieHost
{
    protected $table = 'fleet_server_nodes';

    public function checks(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Check::class , 'host_id');
    }

    public function monitoredChecks(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->checks();
    }
}
