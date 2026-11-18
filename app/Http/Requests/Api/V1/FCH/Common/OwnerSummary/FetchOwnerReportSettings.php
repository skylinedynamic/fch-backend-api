<?php

namespace App\Http\Requests\Api\V1\FCH\Common\OwnerSummary;

use App\Enums\Permission;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The weekly report is Business Insights by email: managing who gets it takes
 * manage business insights report, a developer-only permission.
 */
class FetchOwnerReportSettings extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null && $user->checkPermissionTo(Permission::ManageBusinessInsightsReport->value, 'api');
    }

    public function rules(): array
    {
        return [];
    }
}
