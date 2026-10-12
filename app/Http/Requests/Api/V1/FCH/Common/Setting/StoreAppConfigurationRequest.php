<?php

namespace App\Http\Requests\Api\V1\FCH\Common\Setting;

use App\Http\Requests\Api\V1\BaseApiRequest;
use App\Models\Common\AppConfiguration;
use Illuminate\Validation\Rule;

class StoreAppConfigurationRequest extends BaseApiRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            // The openai module is read platform-wide, not per partner, so only
            // the host-guarded endpoints (OpenAiConfigurationController,
            // PreQualificationAgentConfigController, AssistantConfigController)
            // may write it.
            'module' => ['required', 'string', Rule::notIn([AppConfiguration::MODULE_OPENAI])],
            'key' => 'nullable|string',
            'value' => 'nullable',
            'settings' => 'nullable|array'
        ];
    }
}
