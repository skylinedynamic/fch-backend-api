<?php

namespace App\Http\Requests\Api\V1\FCH\Common\Setting;

use App\Http\Requests\Api\V1\BaseApiRequest;
use App\Models\Common\AppConfiguration;
use Illuminate\Validation\Rule;

class StoreOpenAiConfigurationRequest extends BaseApiRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Required until a key has actually been stored, so the configuration can
            // never be saved in an unusable state. Once one is stored, blank means
            // "keep the existing key" so it need not be re-pasted on every save.
            // When supplied it must be a syntactically valid OpenAI key — a stray
            // capital "Sk-" or surrounding whitespace produces a 401 at run time,
            // so it is rejected here rather than silently stored.
            'api_key' => [
                $this->hasStoredApiKey() ? 'nullable' : 'required',
                'string',
                'max:300',
                'regex:/^sk-[A-Za-z0-9_\-]+$/',
            ],

            // Optional: blank falls back to the server default model.
            'aue_model' => ['nullable', 'string', 'max:100', 'regex:/^[A-Za-z0-9._\-]+$/'],

            // Validated against the same config the Settings screen renders from.
            'reasoning_effort' => [
                'required',
                'string',
                Rule::in(array_column((array) config('openai.reasoning_efforts', []), 'value')),
            ],
        ];
    }

    /**
     * Whether a key is already saved in Settings, which decides if this request
     * may omit one.
     */
    protected function hasStoredApiKey(): bool
    {
        try {
            return filled(AppConfiguration::get('openai_api_key', null, AppConfiguration::MODULE_OPENAI));
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function messages(): array
    {
        return [
            'api_key.required' => __('openai_validation.api_key_required'),
            'api_key.regex' => __('openai_validation.api_key_regex'),
            'api_key.string' => __('openai_validation.api_key_string'),
            'api_key.max' => __('openai_validation.api_key_max'),

            'aue_model.regex' => __('openai_validation.aue_model_regex'),
            'aue_model.string' => __('openai_validation.aue_model_string'),
            'aue_model.max' => __('openai_validation.aue_model_max'),

            'reasoning_effort.required' => __('openai_validation.reasoning_effort_required'),
            'reasoning_effort.in' => __('openai_validation.reasoning_effort_in'),
        ];
    }

    protected function prepareForValidation(): void
    {
        // Trim before validating so a copy-paste with surrounding whitespace is
        // corrected rather than rejected outright.
        $this->merge([
            'api_key' => is_string($this->api_key) ? trim($this->api_key) : $this->api_key,
            'aue_model' => is_string($this->aue_model) ? trim($this->aue_model) : $this->aue_model,
        ]);
    }
}
