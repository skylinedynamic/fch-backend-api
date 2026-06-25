<?php

namespace App\Http\Requests\Api\V1\FCH\Common\FundingRequest;

use App\Models\FundingRequest\FundingRequest;
use App\Http\Requests\Api\V1\BaseApiRequest;

class GetFundingRequestByIdRequest extends BaseApiRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [];
    }

    public function withValidator($validator)
    {
        $validator->after(function ($validator) {
            $id = $this->route('id');
            if (!$id || !FundingRequest::where('id', $id)->exists()) {
                $validator->errors()->add('id', __('funding_request.funding_request_not_found'));
            }
        });
    }
}
