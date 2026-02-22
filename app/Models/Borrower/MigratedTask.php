<?php

namespace App\Models\Borrower;

use App\Models\CreditBuilder\CreditBuilderVendor;
use App\Models\CreditBuilder\Tier;
use App\Models\CreditBuilder\TierTask;
use App\Models\Partner\Partner;
use App\Models\User\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class MigratedTask extends Model
{
    use SoftDeletes;

    protected $table = 'migrated_tasks';

    protected $fillable = [
        'borrower_id',
        'partner_id',
        'task_id',
        'tier_from',
        'vendor_id',
        'tier_to',
        'status',
        'migrated_by',
    ];

    public function borrower()
    {
        return $this->belongsTo(Borrower::class);
    }

    public function partner()
    {
        return $this->belongsTo(Partner::class);
    }

    public function task()
    {
        return $this->belongsTo(TierTask::class);
    }

    public function tierFrom()
    {
        return $this->belongsTo(Tier::class, 'tier_from');
    }

    public function tierTo()
    {
        return $this->belongsTo(Tier::class, 'tier_to');
    }

    public function vendor()
    {
        return $this->belongsTo(CreditBuilderVendor::class);
    }

    public function migratedBy()
    {
        return $this->belongsTo(User::class, 'migrated_by');
    }
}
