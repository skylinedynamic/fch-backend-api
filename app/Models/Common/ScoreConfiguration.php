<?php

namespace App\Models\Common;

use Illuminate\Database\Eloquent\Model;

class ScoreConfiguration extends Model
{
    protected $table = 'score_configurations';

    protected $fillable = [
        'category',
        'model_slug',
        'factor_slug',
        'label',
        'color',
        'min_value',
        'max_value',
        'numeric_value',
        'conditions',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'min_value' => 'decimal:2',
            'max_value' => 'decimal:2',
            'numeric_value' => 'decimal:2',
            'conditions' => 'json',
            'sort_order' => 'integer',
        ];
    }

    public const CATEGORY_MODEL_WEIGHT = 'model_weight';
    public const CATEGORY_SUBSCORE_TIER = 'subscore_tier';
    public const CATEGORY_READINESS_BAND = 'readiness_band';
    public const CATEGORY_LATE_PAYMENT_MODIFIER = 'late_payment_modifier';
    public const CATEGORY_TIME = 'time';

    public const MODEL_FUNDING_READINESS = 'funding_readiness';
    public const MODEL_APPROVAL_PROBABILITY = 'approval_probability';
    public const MODEL_APPROVAL_ODDS = 'approval_odds';
    public const MODEL_GENERAL_SETTING = 'general_setting';

    public const FACTOR_TIME_SETTING = 'time_setting';

    // Revenue Strength Statuses (Readiness subscore labels)
    public const STATUS_REVENUE_STRONG = 'Strong';
    public const STATUS_REVENUE_MODERATE = 'Moderate';
    public const STATUS_REVENUE_LIMITED = 'Limited';
    public const STATUS_REVENUE_WEAK = 'Weak';

    // Tradeline Depth Statuses (Readiness subscore labels)
    public const STATUS_TRADE_MULTIPLE_HIGH = 'Multiple High-Limit & Installment Depth';
    public const STATUS_TRADE_GOOD_DEPTH = 'Good Depth with High-Limit account';
    public const STATUS_TRADE_MODERATE = 'Moderate Depth';
    public const STATUS_TRADE_THIN = 'Thin File';

    // Banking Relationship Statuses (Readiness subscore labels)
    public const STATUS_BANKING_STRONG = 'Strong';
    public const STATUS_BANKING_MODERATE = 'Moderate';
    public const STATUS_BANKING_LIMITED = 'Limited';
    public const STATUS_BANKING_NONE = 'None';

    // Time in Business Statuses (Readiness subscore labels)
    public const STATUS_TIME_ESTABLISHED = 'Established';
    public const STATUS_TIME_MATURE = 'Mature';
    public const STATUS_TIME_YOUNG = 'Young';
    public const STATUS_TIME_EARLY_STAGE = 'Early Stage';
    public const STATUS_TIME_STARTUP = 'Startup';

    // Approval Probability Tier Labels — Banking Relationship factor
    public const PROB_STATUS_BANKING_STRONG = 'Strong business banking relationship';
    public const PROB_STATUS_BANKING_MODERATE = 'Moderate banking relationship';
    public const PROB_STATUS_BANKING_LIMITED = 'Limited banking relationship';
    public const PROB_STATUS_BANKING_NONE = 'No meaningful banking relationship';

    // Approval Probability Tier Labels — Tradeline Depth factor
    public const PROB_STATUS_TRADE_MULTIPLE_HIGH = 'Multiple High-Limit & Installment Depth';
    public const PROB_STATUS_TRADE_GOOD_DEPTH = 'Good Depth with High-Limit account';
    public const PROB_STATUS_TRADE_MODERATE = 'Moderate tradeline depth';
    public const PROB_STATUS_TRADE_THIN = 'Thin file';

    // Approval Probability Tier Labels — Revenue / Income factor
    public const PROB_STATUS_REVENUE_STRONG = 'Strong revenue or strong documented income';
    public const PROB_STATUS_REVENUE_MODERATE = 'Moderate revenue/income';
    public const PROB_STATUS_REVENUE_LIMITED = 'Limited revenue/income';
    public const PROB_STATUS_REVENUE_STARTUP = 'Startup with no revenue';
}
