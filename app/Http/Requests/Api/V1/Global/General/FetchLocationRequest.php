<?php

namespace App\Http\Requests\Api\V1\Global\General;

use App\Http\Requests\Api\V1\BaseApiRequest;
use App\Support\Locations;
use Illuminate\Validation\Rule;

/**
 * Query parameters for GET /common/locations.
 *
 * Unauthenticated, like the endpoint itself - the data is public reference
 * material and both portals read it before anyone holds a token.
 */
class FetchLocationRequest extends BaseApiRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Narrow the response to one country. Validated against the codes
            // actually configured, so a typo is answered with a clear message
            // rather than an empty list the caller has to interpret.
            'country' => [
                'nullable',
                'string',
                Rule::in(array_column(Locations::all(), 'code')),
            ],

            // `list` mirrors the config and is canonical; `map` is the same data
            // keyed by country and state code, which is the shape the partner
            // portal's address components read.
            'format' => ['nullable', 'string', Rule::in(['list', 'map'])],
        ];
    }

    public function messages(): array
    {
        return [
            'country.in' => __('common.location_country_not_found'),
            'format.in' => __('common.location_format_invalid'),
        ];
    }
}
