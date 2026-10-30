<?php

namespace App\Http\Requests\Api\V1\FCH\Common\Borrower;

use App\Http\Requests\Api\V1\BaseApiRequest;
use App\Services\FCH\Common\Borrower\BorrowerService;
use Illuminate\Validation\Rule;

/**
 * Which of a client's masked numbers to reveal (BUG-014).
 *
 * Anyone who can open the client may reveal (BorrowerService::getDetails()
 * scopes which clients that is); this only names the field.
 */
class RevealBorrowerFieldRequest extends BaseApiRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'field' => ['required', 'string', Rule::in(BorrowerService::REVEALABLE_FIELDS)],
        ];
    }
}
