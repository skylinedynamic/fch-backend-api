<?php

namespace App\Http\Requests\Api\V1\FCH\Admin\Partner;

use App\Http\Requests\Api\V1\BaseApiRequest;
use App\Models\Partner\Partner;
use App\Rules\DomainNotClaimed;
use Illuminate\Validation\Rule;

class UpdateTenantConfigRequest extends BaseApiRequest
{
    /**
     * A host name: dot-separated labels of letters, digits and inner hyphens,
     * ending in an alphabetic top-level domain (e.g. app.example.com).
     */
    private const HOST_NAME = '/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/';

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Each domain as the domain resolver compares it (getPartnerIdFromOrigin):
     * no scheme, no "www.", no trailing slash, lower case; empty becomes null.
     */
    protected function prepareForValidation(): void
    {
        $clean = function ($value) {
            if (!is_string($value)) {
                return $value;
            }
            $value = strtolower(trim($value));
            $value = preg_replace('/^https?:\/\//', '', $value);
            $value = preg_replace('/^www\./', '', $value);
            $value = rtrim($value, '/');

            return $value === '' ? null : $value;
        };

        $this->merge(collect(['partner_domain', 'borrower_domain'])
            ->filter(fn ($field) => $this->has($field))
            ->mapWithKeys(fn ($field) => [$field => $clean($this->input($field))])
            ->all());
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        // The partner being configured: the route's on the host's admin screen,
        // otherwise the user's own - never entity_id, which for a staff user is
        // their employee id (see TenantConfigController).
        $partnerId = $this->route('id') ?: auth()->user()->partner_id;
        // Neither portal domain may be the host's, another partner's (on either
        // portal), or this partner's other one (R3-BUG-020).
        $notTaken = fn (string $column, ?string $other) => new DomainNotClaimed($column, $partnerId ? (int) $partnerId : null, $other);

        return [
            'active_custom_url' => ['required', 'boolean'],
            'partner_domain' => [
                Rule::requiredIf($this->boolean('active_custom_url')),
                'nullable',
                'string',
                'max:255',
                'bail',
                'regex:' . self::HOST_NAME,
                $notTaken('partner_domain', null),
            ],
            // Optional even with the custom domain on.
            'borrower_domain' => [
                'nullable',
                'string',
                'max:255',
                'bail',
                'regex:' . self::HOST_NAME,
                $notTaken('borrower_domain', $this->has('partner_domain')
                    ? $this->input('partner_domain')
                    : Partner::whereKey($partnerId)->value('partner_domain')),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'partner_domain.required' => __('partner.validation.partner_domain.portal_required'),
            'partner_domain.regex' => __('partner.validation.partner_domain.host'),
            'borrower_domain.regex' => __('partner.validation.borrower_domain.host'),
        ];
    }
}
