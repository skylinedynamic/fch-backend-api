<?php

namespace App\Http\Requests\Api\V1\FCH\Admin\CreditBuilder\TierTask\Concerns;

use App\Models\CreditBuilder\Tier;
use App\Models\CreditBuilder\TierTask;
use Illuminate\Validation\Validator;

/**
 * A task on the vendor tier (has_vendor - Tier 1) that is not manually
 * verified is verified through its vendor, so it must name one (BUG-095).
 * Judged on the task as it will be saved: what the request sends, else what
 * an edited task already has.
 */
trait VendorTierTaskRules
{
    /** The task being edited; null when creating one. */
    abstract protected function taskBeingEdited(): ?TierTask;

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $task = $this->taskBeingEdited();
            $tierId = $this->input('tier_id', $task?->tier_id);
            $manual = $this->has('manual_verification') ? $this->boolean('manual_verification') : (bool) $task?->manual_verification;
            $vendorId = $this->has('vendor_id') ? $this->input('vendor_id') : $task?->vendor_id;

            if ($manual || filled($vendorId) || !$tierId) {
                return;
            }

            if (Tier::whereKey($tierId)->value('has_vendor')) {
                $validator->errors()->add('vendor_id', __('tier_task.validation_vendor_required'));
            }
        });
    }
}
