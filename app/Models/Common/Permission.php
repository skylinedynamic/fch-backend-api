<?php
namespace App\Models\Common;
use App\Models\Business\Business;
use Spatie\Permission\Models\Permission as SpatiePermission;

class Permission extends SpatiePermission
{
    protected $fillable = ['name','guard_name','module', 'is_host', 'is_certified', 'is_developer'];

    protected $casts = [
        'is_developer' => 'boolean',
    ];

    public function business() { return $this->belongsTo(Business::class); }

    /** Leaves out the permissions only the Developer role may hold. */
    public function scopeWithoutDeveloperOnly($query)
    {
        return $query->where($this->qualifyColumn('is_developer'), false);
    }
}
