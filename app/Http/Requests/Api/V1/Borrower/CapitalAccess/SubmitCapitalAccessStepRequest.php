<?php

namespace App\Http\Requests\Api\V1\Borrower\CapitalAccess;

use App\Http\Requests\Api\V1\BaseApiRequest;

use App\Models\CreditBuilder\CapitalAccessApplication;

class SubmitCapitalAccessStepRequest extends BaseApiRequest
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
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $sequenceId = $this->route('sequenceId');
        
        $seq = \App\Models\CreditBuilder\CapitalAccessSequence::find($sequenceId);
        $min = $seq ? ($seq->est_limit_min ?? 0) : 0;
        $max = $seq ? ($seq->est_limit_max ?? 999999999) : 999999999;

        $statuses = implode(',', CapitalAccessApplication::STATUSES);
        $approvedStatus = CapitalAccessApplication::STATUS_APPROVED;

        return [
            'status'       => "required|string|in:{$statuses}",
            'credit_limit' => "required_if:status,{$approvedStatus}|nullable|numeric|min:{$min}|max:{$max}",
            'notes'        => 'nullable|string',
        ];
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        $sequenceId = $this->route('sequenceId');
        $seq = \App\Models\CreditBuilder\CapitalAccessSequence::find($sequenceId);
        $min = $seq ? ($seq->est_limit_min ?? 0) : 0;
        $max = $seq ? ($seq->est_limit_max ?? 999999999) : 999999999;

        return [
            'credit_limit.required' => 'Approved Credit Limit is required.',
            'credit_limit.numeric'  => 'Approved Credit Limit must be a number.',
            'credit_limit.min'      => "Approved Credit Limit must be at least {$min}.",
            'credit_limit.max'      => "Approved Credit Limit must be at most {$max}.",
        ];
    }
}
