<?php

namespace App\Http\Requests\Api\V1\FCH\Common\Payment;

use App\Enums\Permission;
use Illuminate\Foundation\Http\FormRequest;

class RejectPayment extends FormRequest
{
    /**
     * Rejecting is the other half of the same decision: only a user holding
     * 'can approve payment', as the portal's Reject button (R3-BUG-037).
     */
    public function authorize(): bool
    {
        return (bool) $this->user()?->checkPermissionTo(Permission::ApprovePayment->value, 'api');
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:1000'],
            'note'   => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reason.required' => __('payment_validation.rejection_reason_required'),
            'reason.string'   => __('payment_validation.rejection_reason_string'),
            'reason.max'      => __('payment_validation.rejection_reason_max'),
        ];
    }
}
