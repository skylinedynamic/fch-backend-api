<?php

namespace App\Models\Walkthrough;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Where an account (a borrower, or later a partner) is in one guided
 * walkthrough. One row per account per walkthrough.
 *
 * @property string $entity_type
 * @property int $entity_id
 * @property string $walkthrough
 * @property ?string $current_step
 * @property ?array $skipped_steps
 * @property ?string $selected_path
 * @property ?\Illuminate\Support\Carbon $path_selected_at
 * @property ?\Illuminate\Support\Carbon $completed_at
 */
class WalkthroughProgress extends Model
{
    protected $table = 'walkthrough_progress';

    /** The borrower portal's Getting Started journey for paid clients (OnboardingStepService). */
    public const BORROWER_ONBOARDING = 'borrower_onboarding';

    /** The short version for free-trial borrowers: profile and credit profile only. */
    public const BORROWER_FREE_TRIAL = 'borrower_free_trial';

    public const WALKTHROUGHS = [
        self::BORROWER_ONBOARDING,
        self::BORROWER_FREE_TRIAL,
    ];

    protected $fillable = [
        'entity_type',
        'entity_id',
        'walkthrough',
        'current_step',
        'skipped_steps',
        'selected_path',
        'path_selected_at',
        'completed_at',
    ];

    protected $casts = [
        'skipped_steps' => 'array',
        'path_selected_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function entity(): MorphTo
    {
        return $this->morphTo();
    }

    /** This account's row for the walkthrough, unsaved if it has none yet. */
    public static function for(Model $entity, string $walkthrough): self
    {
        return static::firstOrNew([
            'entity_type' => $entity::class,
            'entity_id' => $entity->getKey(),
            'walkthrough' => $walkthrough,
        ]);
    }

    public function hasSkipped(string $step): bool
    {
        return in_array($step, $this->skipped_steps ?? [], true);
    }
}
