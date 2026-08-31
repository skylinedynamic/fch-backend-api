<?php

namespace App\Http\Requests\Api\V1\FCH\Common\Billing;

use App\Models\User\User;
use Illuminate\Foundation\Http\FormRequest;

class FetchUpcomingBillingRequest extends FormRequest
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
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'search'     => ['nullable', 'string'],
            'status'     => ['nullable'],
            'filters'    => ['nullable', 'array'],
            'page'       => ['nullable', 'integer', 'min:1'],
            'per_page'   => ['nullable', 'integer', 'min:1'],
            'sort_field' => ['nullable', 'string'],
            'sort_order' => ['nullable', 'in:asc,desc'],
            // Must be declared here — the controller passes validated() through, so
            // anything missing from these rules is silently dropped before it ever
            // reaches the service.
            'view'       => ['nullable', 'in:all,paused,overdue,scheduled'],
            // Same vocabulary the frontend uses (ENTITY_TYPE_* in appConfig.ts).
            'entity_type' => ['nullable', 'in:' . User::PARTNER . ',' . User::BORROWER],
        ];
    }
}
