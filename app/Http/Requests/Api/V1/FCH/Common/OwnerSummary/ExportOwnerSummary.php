<?php

namespace App\Http\Requests\Api\V1\FCH\Common\OwnerSummary;

use App\Services\FCH\Common\OwnerSummary\OwnerSummaryExportPdf;
use Illuminate\Validation\Rule;

/**
 * Business Insights › Export PDF: the screen's own request (who may see it,
 * the period, the payments) plus which report - summary or details.
 */
class ExportOwnerSummary extends FetchOwnerSummary
{
    public function rules(): array
    {
        return parent::rules() + [
            'type' => ['required', 'string', Rule::in(OwnerSummaryExportPdf::TYPES)],
        ];
    }

    public function messages(): array
    {
        return parent::messages() + [
            'type.required' => __('owner_summary.export_type_required'),
            'type.in' => __('owner_summary.export_type_invalid'),
        ];
    }

    public function type(): string
    {
        return $this->validated('type');
    }
}
