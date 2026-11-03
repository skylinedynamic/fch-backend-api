<?php

namespace App\Http\Requests\Api\V1\FCH\Common\Audit;

use App\Models\Audit\FundingApplicationAuditEvent;
use App\Services\Audit\FundingApplicationAuditTrail;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** GET .../funding-application/{id}/audit-trail (and /export): its filters, and the export format. */
class FundingApplicationAuditTrailRequest extends FormRequest
{
    public const EXPORT_CSV = 'csv';
    public const EXPORT_PDF = 'pdf';
    public const EXPORT_FORMATS = [self::EXPORT_CSV, self::EXPORT_PDF];
    public const EXPORT_MIME_TYPES = [
        self::EXPORT_CSV => 'text/csv; charset=UTF-8',
        self::EXPORT_PDF => 'application/pdf',
    ];

    public const SORT_ASC = 'asc';
    public const SORT_DESC = 'desc';

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'event' => ['nullable', Rule::in(FundingApplicationAuditEvent::EVENTS)],
            'search' => ['nullable', 'string', 'max:191'],
            'sort_dir' => ['nullable', Rule::in([self::SORT_ASC, self::SORT_DESC])],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1'],
            'format' => ['nullable', Rule::in(self::EXPORT_FORMATS)],
        ];
    }

    public function perPage(): int
    {
        return min((int) ($this->validated('per_page') ?? config('audit.default_per_page')), FundingApplicationAuditTrail::MAX_PER_PAGE);
    }

    public function sortDirection(): string
    {
        return $this->validated('sort_dir') === self::SORT_ASC ? self::SORT_ASC : self::SORT_DESC;
    }

    public function exportFormat(): string
    {
        return $this->validated('format') ?? self::EXPORT_CSV;
    }
}
