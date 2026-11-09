<?php

namespace App\Http\Requests\Api\V1\FCH\Common\OwnerSummary;

use App\Services\FCH\Common\Commission\CommissionViewer;
use App\Services\FCH\Common\OwnerSummary\OwnerSummaryPeriod;
use App\Services\FCH\Common\OwnerSummary\OwnerSummaryService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The owner summary is the host's whole book, so only who sees that on the
 * Commissions screen may see it: the host owner (CommissionViewer::HOST_OWNER).
 * The route already admits only the host's team (host-partner middleware).
 */
class FetchOwnerSummary extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null && CommissionViewer::for($user)->is(CommissionViewer::HOST_OWNER);
    }

    public function rules(): array
    {
        return [
            'period' => ['nullable', 'string', Rule::in(OwnerSummaryPeriod::KEYS)],
            'environment' => ['nullable', 'string', Rule::in(array_keys(OwnerSummaryService::ENVIRONMENTS))],
        ];
    }

    public function period(): string
    {
        return $this->validated('period') ?? OwnerSummaryPeriod::DEFAULT;
    }

    public function environment(): string
    {
        return $this->validated('environment') ?? OwnerSummaryService::ENVIRONMENT_PRODUCTION;
    }

    /** The viewer's timezone (X-Timezone), falling back to the app's when it is missing or unknown. */
    public function timezone(): string
    {
        $timezone = (string) $this->header('X-Timezone');

        return in_array($timezone, timezone_identifiers_list(), true) ? $timezone : config('app.timezone', 'UTC');
    }
}
