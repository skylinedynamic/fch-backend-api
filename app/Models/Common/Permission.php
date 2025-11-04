<?php
namespace App\Models\Common;
use App\Models\Business\Business;
use Spatie\Permission\Models\Permission as SpatiePermission;

class Permission extends SpatiePermission
{
    protected $fillable = ['name','guard_name','business_id'];

    public function business() { return $this->belongsTo(Business::class); }
}
