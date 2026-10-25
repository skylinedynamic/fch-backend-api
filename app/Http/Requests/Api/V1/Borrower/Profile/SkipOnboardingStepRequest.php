<?php

namespace App\Http\Requests\Api\V1\Borrower\Profile;

use App\Http\Requests\Api\V1\BaseApiRequest;
use App\Services\Borrower\Profile\OnboardingStepService;
use Illuminate\Validation\Rule;

/**
 * A borrower skipping a Getting Started step, or going back to one they
 * skipped. Either way only a skippable step; whether it can be skipped or
 * resumed right now is checked by OnboardingStepService::skip() / resume().
 */
class SkipOnboardingStepRequest extends BaseApiRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'step' => ['required', 'string', Rule::in(OnboardingStepService::SKIPPABLE_STEPS)],
        ];
    }
}
