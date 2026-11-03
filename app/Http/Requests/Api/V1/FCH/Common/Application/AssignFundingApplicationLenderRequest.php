<?php

namespace App\Http\Requests\Api\V1\FCH\Common\Application;

use App\Http\Requests\Api\V1\BaseApiRequest;
use App\Models\Borrower\FundingApplication;

/**
 * Assign a lender to a funding application - the host's team only, as Lender
 * Information is only shown to the host.
 */
class AssignFundingApplicationLenderRequest extends BaseApiRequest
{
    public function authorize(): bool
    {
        return FundingApplication::isHostTeam($this->user());
    }

    public function rules(): array
    {
        return [
            'lender_ids' => ['required', 'array', 'size:1'],
            'lender_ids.*' => ['integer', 'exists:lenders,id'],
        ];
    }
}
