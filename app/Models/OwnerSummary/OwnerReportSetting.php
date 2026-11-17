<?php

namespace App\Models\OwnerSummary;

use App\Models\Partner\Partner;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Who gets the host owner's weekly report, and which week last went out. */
class OwnerReportSetting extends Model
{
    public const MAX_RECIPIENTS = 10;

    protected $fillable = ['partner_id', 'is_enabled', 'recipients', 'last_sent_period_from'];

    protected $attributes = [
        'is_enabled' => false,
        'recipients' => '[]',
    ];

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'recipients' => 'array',
            'last_sent_period_from' => 'date',
        ];
    }

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }
}
