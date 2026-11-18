<?php

namespace App\Http\Requests\Api\V1\FCH\Common\Calendly;

use App\Http\Requests\Api\V1\BaseApiRequest;
use App\Services\FCH\Common\Calendly\CalendlyService;
use Illuminate\Validation\Rule;

/**
 * Sync now. Without a direction: Calendly's meetings come in, a week back to
 * two months ahead (the page's quiet sync on opening). With one - pull, push
 * or both - for the dates the calendar shows (from/to, in the viewer's zone),
 * else the current month.
 */
class CalendlySyncRequest extends BaseApiRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'partner_id' => 'nullable|integer',
            'direction' => ['nullable', 'string', Rule::in(CalendlyService::SYNC_DIRECTIONS)],
            'from' => 'nullable|date_format:Y-m-d|required_with:to',
            // Up to a few months at once: every meeting in it may be booked in Calendly.
            'to' => 'nullable|date_format:Y-m-d|after_or_equal:from|before_or_equal:'.$this->maxTo(),
            'timezone' => 'nullable|string|timezone:all',
        ];
    }

    private function maxTo(): string
    {
        $from = $this->input('from');

        return is_string($from) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)
            ? date('Y-m-d', strtotime($from.' +93 days'))
            : '9999-12-31';
    }
}
