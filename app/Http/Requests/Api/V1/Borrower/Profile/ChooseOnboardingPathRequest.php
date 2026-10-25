<?php

namespace App\Http\Requests\Api\V1\Borrower\Profile;

use App\Http\Requests\Api\V1\BaseApiRequest;
use App\Services\Borrower\Profile\OnboardingStepService;
use Illuminate\Validation\Rule;

/**
 * A borrower choosing what they are looking for once their results are
 * ready. Only one of the paths on offer; whether the borrower can choose right
 * now is checked by OnboardingStepService::choosePath().
 */
class ChooseOnboardingPathRequest extends BaseApiRequest
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
            'path' => ['required', 'string', Rule::in(OnboardingStepService::PATHS)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'path.in' => __('borrower.onboarding_path_not_available'),
        ];
    }
}
