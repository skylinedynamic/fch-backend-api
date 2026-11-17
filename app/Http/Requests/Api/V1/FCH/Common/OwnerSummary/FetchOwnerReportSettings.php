<?php

namespace App\Http\Requests\Api\V1\FCH\Common\OwnerSummary;

use App\Services\FCH\Common\Commission\CommissionViewer;
use Illuminate\Foundation\Http\FormRequest;

/** The report is the owner summary by email, so whoever may see that may manage this. */
class FetchOwnerReportSettings extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null && CommissionViewer::for($user)->is(CommissionViewer::HOST_OWNER);
    }

    public function rules(): array
    {
        return [];
    }
}
