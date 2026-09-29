<?php

namespace App\Http\Requests\Api\V1\FCH\Common\Assistant;

use App\Http\Requests\Api\V1\BaseApiRequest;
use App\Models\Assistant\AiAssistantConfig;
use Illuminate\Validation\Rule;

class StoreAiAssistantConfigRequest extends BaseApiRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'assistant_type' => ['required', Rule::in(AiAssistantConfig::TYPES)],
            'model' => ['nullable', 'string', 'max:100'],
            'reasoning_effort' => ['nullable', 'string', 'max:20'],
            'max_output_tokens' => ['nullable', 'integer', 'min:1', 'max:32000'],
            'is_enabled' => ['nullable', 'boolean'],
        ];
    }
}
