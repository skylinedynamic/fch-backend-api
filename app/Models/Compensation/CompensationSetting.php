<?php

namespace App\Models\Compensation;

use App\Models\Employee\Employee;
use App\Models\User\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class CompensationSetting extends Model
{
    protected $table = 'compensation_settings';

    /**
     * Entities that can have a compensation setup, keyed by the slug the API
     * accepts. Clients send the slug, never a model class — the mapping stays
     * server-side so a request can't point entity_type at an arbitrary class.
     */
    public const ENTITY_TYPES = [
        'employee' => Employee::class,
        'user' => User::class,
    ];

    public const PAID_BY_NONE = 'None';
    public const PAID_BY_HOURLY = 'Hourly';
    public const PAID_BY_SALARY = 'Salary';
    public const PAID_BY_PER_DEAL = 'Per Deal';

    public const PAYOUT_TYPE_COMMISSION = 'Commission';
    public const PAYOUT_TYPE_FLAT_AMOUNT = 'Flat Amount';

    public const SERVICE_ALL = 'All';

    public const PAID_BY_OPTIONS = [
        self::PAID_BY_NONE,
        self::PAID_BY_HOURLY,
        self::PAID_BY_SALARY,
        self::PAID_BY_PER_DEAL,
    ];

    public const PAYOUT_TYPE_OPTIONS = [
        self::PAYOUT_TYPE_COMMISSION,
        self::PAYOUT_TYPE_FLAT_AMOUNT,
    ];

    protected $fillable = [
        'partner_id',
        'entity_type',
        'entity_id',
        'paid_by',
        'service',
        'payout_type',
        'amount',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
    ];

    public function entity(): MorphTo
    {
        return $this->morphTo();
    }
}
