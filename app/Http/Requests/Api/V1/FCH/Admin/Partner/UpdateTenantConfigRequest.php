<?php

namespace App\Http\Requests\Api\V1\FCH\Admin\Partner;

use App\Http\Requests\Api\V1\BaseApiRequest;
use App\Models\Partner\Partner;
use Illuminate\Validation\Rule;

class UpdateTenantConfigRequest extends BaseApiRequest
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
        $partnerId = $this->id ?: auth()->user()->entity_id;
        $baseUrl = preg_replace('/^https?:\/\//', '', config('app.base_website'));
        $restrictedDomains = array_map(function ($keyword) use ($baseUrl) {
            return $keyword . '.' . $baseUrl;
        }, Partner::RESTRICTED_DOMAINS);

        return [
            'active_custom_url' => ['required', 'boolean'],
            'partner_domain' => [
                Rule::requiredIf($this->active_custom_url),
                'nullable',
                'string',
                'max:255',
                Rule::unique('partners', 'partner_domain')->ignore($partnerId)->whereNull('deleted_at'),
                Rule::notIn($restrictedDomains)
            ],
            'borrower_domain' => [
                Rule::requiredIf($this->active_custom_url),
                'nullable',
                'string',
                'max:255',
                Rule::unique('partners', 'borrower_domain')->ignore($partnerId)->whereNull('deleted_at'),
                Rule::notIn($restrictedDomains)
            ],
        ];
    }
}
