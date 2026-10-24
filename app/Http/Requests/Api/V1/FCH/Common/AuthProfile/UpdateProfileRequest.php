<?php

namespace App\Http\Requests\Api\V1\FCH\Common\AuthProfile;

use App\Http\Requests\Api\V1\BaseApiRequest;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Auth;
use App\Rules\UsPhoneNumber;

class UpdateProfileRequest extends BaseApiRequest
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
        $userId = Auth::id();

        return [
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'phone_number' => ['nullable', 'string', new UsPhoneNumber()],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')->ignore($userId)],
            'image' => ['nullable', 'string'],
        ];
    }
}
