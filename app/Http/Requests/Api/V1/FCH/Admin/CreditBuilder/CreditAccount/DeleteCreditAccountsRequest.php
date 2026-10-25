<?php

namespace App\Http\Requests\Api\V1\FCH\Admin\CreditBuilder\CreditAccount;

use App\Http\Requests\Api\V1\BaseApiRequest;

/** The cards selected in the Credit Accounts list to delete together. */
class DeleteCreditAccountsRequest extends BaseApiRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'ids' => ['required', 'array', 'min:1', 'max:500'],
            'ids.*' => ['integer', 'distinct'],
        ];
    }
}
