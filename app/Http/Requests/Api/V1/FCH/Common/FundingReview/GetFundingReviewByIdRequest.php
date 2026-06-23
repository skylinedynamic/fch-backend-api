<?php

namespace App\Http\Requests\Api\V1\FCH\Common\FundingReview;

use App\Models\FundingReview\FundingReview;
use Illuminate\Foundation\Http\FormRequest;

class GetFundingReviewByIdRequest extends FormRequest
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
            if (!$id || !FundingReview::where('id', $id)->exists()) {
                $validator->errors()->add('id', 'Funding review not found.');
            }
        });
    }
}
