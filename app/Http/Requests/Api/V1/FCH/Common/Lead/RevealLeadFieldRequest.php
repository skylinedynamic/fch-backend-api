<?php

namespace App\Http\Requests\Api\V1\FCH\Common\Lead;

use App\Http\Requests\Api\V1\BaseApiRequest;
use App\Services\FCH\Common\Lead\LeadService;
use Illuminate\Validation\Rule;

/**
 * Which of a lead's masked numbers to reveal (R3-BUG-004, as BUG-014 for
 * clients).
 *
 * Anyone who can open the lead may reveal (Lead::visibleTo() scopes which
 * leads that is); this only names the field.
 */
class RevealLeadFieldRequest extends BaseApiRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'field' => ['required', 'string', Rule::in(LeadService::REVEALABLE_FIELDS)],
        ];
    }
}
