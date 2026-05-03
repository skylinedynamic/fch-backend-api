<?php

namespace App\Http\Requests\Api\V1\FCH\Common\AuthProfile;

use App\Http\Requests\Api\V1\BaseApiRequest;

class UpdateProfilePictureRequest extends BaseApiRequest
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
            'image' => ['required', 'string'],
        ];
    }
}
