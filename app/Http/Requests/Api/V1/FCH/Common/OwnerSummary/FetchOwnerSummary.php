<?php

namespace App\Http\Requests\Api\V1\FCH\Common\OwnerSummary;

use App\Enums\Permission;
use App\Models\User\User;
use App\Services\FCH\Common\OwnerSummary\OwnerSummaryPeriod;
use App\Services\FCH\Common\OwnerSummary\OwnerSummaryService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Business Insights - the owner summary, the host's whole book - is for
 * whoever holds view business insights, a developer-only permission (only the
 * Developer role can be given it). The route already admits only the host's
 * team (host-partner middleware).
 */
class FetchOwnerSummary extends FormRequest
{
    public function authorize(): bool
    {
        return $this->holds(Permission::ViewBusinessInsights);
    }

    protected function holds(Permission $permission): bool
    {
        $user = $this->user();

        return $user !== null && $user->checkPermissionTo($permission->value, 'api');
    }

    /**
     * Whose book the summary is: always the host's own login (as for the
     * weekly report), whoever views it - a Developer is usually host staff,
     * whose commission view would only cover its own clients.
     */
    public function owner(): User
    {
        return $this->user()->partner?->partnerUser ?? $this->user();
    }

    public function rules(): array
    {
        $onlyForCustom = 'exclude_unless:period,' . OwnerSummaryPeriod::CUSTOM;
        $today = CarbonImmutable::now($this->timezone())->toDateString();

        return [
            'period' => ['nullable', 'string', Rule::in(OwnerSummaryPeriod::KEYS)],
            'environment' => ['nullable', 'string', Rule::in(array_keys(OwnerSummaryService::ENVIRONMENTS))],
            'from' => [$onlyForCustom, 'required', 'date_format:Y-m-d', 'before_or_equal:' . $today],
            'to' => [$onlyForCustom, 'required', 'date_format:Y-m-d', 'before_or_equal:' . $today, 'after_or_equal:from'],
        ];
    }

    public function messages(): array
    {
        return [
            'from.required' => __('owner_summary.from_required'),
            'from.date_format' => __('owner_summary.from_invalid'),
            'from.before_or_equal' => __('owner_summary.from_future'),
            'to.required' => __('owner_summary.to_required'),
            'to.date_format' => __('owner_summary.to_invalid'),
            'to.before_or_equal' => __('owner_summary.to_future'),
            'to.after_or_equal' => __('owner_summary.to_before_from'),
        ];
    }

    public function period(): string
    {
        return $this->validated('period') ?? OwnerSummaryPeriod::DEFAULT;
    }

    public function summaryPeriod(): OwnerSummaryPeriod
    {
        return $this->period() === OwnerSummaryPeriod::CUSTOM
            ? OwnerSummaryPeriod::custom($this->validated('from'), $this->validated('to'), $this->timezone())
            : OwnerSummaryPeriod::make($this->period(), $this->timezone());
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
