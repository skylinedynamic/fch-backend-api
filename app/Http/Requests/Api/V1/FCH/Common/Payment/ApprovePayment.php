<?php

namespace App\Http\Requests\Api\V1\FCH\Common\Payment;

use App\Enums\Permission;
use Illuminate\Foundation\Http\FormRequest;

class ApprovePayment extends FormRequest
{
    /**
     * Only a user holding Permission::ApprovePayment - the permission the portal
     * shows the Approve button by. It used to be checked in the portal only, so
     * staff without it approved a client's payment through the API, which marks
     * the invoice paid and activates the enrollment (R3-BUG-037).
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
            'note' => ['nullable', 'string', 'max:1000'],
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
            'note.required' => __('payment_validation.note_required'),
            'note.string'   => __('payment_validation.note_string'),
            'note.max'      => __('payment_validation.note_max'),
        ];
    }
}
