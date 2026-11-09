<?php

namespace App\Http\Requests\Api\V1\Global\General;

use App\Http\Requests\Api\V1\BaseApiRequest;

/**
 * Query parameters for GET /common/funding-sequences: the funding type
 * (Preferred Funding Type) whose funding sequences are listed.
 */
class FetchFundingSequenceOptionsRequest extends BaseApiRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'funding_type' => ['required', 'string', 'max:255'],
        ];
    }
}
