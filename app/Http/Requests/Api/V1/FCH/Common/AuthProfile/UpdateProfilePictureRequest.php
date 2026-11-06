<?php

namespace App\Http\Requests\Api\V1\FCH\Common\AuthProfile;

use App\Http\Requests\Api\V1\BaseApiRequest;
use App\Rules\NoPathTraversal;
use App\Rules\StoredProfilePicture;

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
        // A JPG/PNG from the user's own file manager folder, within the picture
        // size limit and a real image (BUG-014).
        return [
            'image' => ['required', 'string', new NoPathTraversal(), new StoredProfilePicture($this->user()?->profilePictureFolder())],
        ];
    }
}
