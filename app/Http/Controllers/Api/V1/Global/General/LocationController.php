<?php

namespace App\Http\Controllers\Api\V1\Global\General;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Global\General\FetchLocationRequest;
use App\Http\Resources\Global\General\LocationResource;
use App\Support\Locations;
use Illuminate\Http\JsonResponse;

/**
 * Countries, states and cities.
 *
 * Serves config/locations.php, which is the one place this data is defined -
 * for the server, and now for both portals, which used to carry their own
 * copies. It previously served a second copy from Database\Seeders\
 * LocationHelper, so the API and everything reading the config could disagree,
 * and did: the config carries the "Unknown" state and city entries.
 *
 * Unauthenticated on purpose. It is public reference data, and both portals load
 * it at bootstrap - on the login screen, before anyone holds a token.
 */
class LocationController extends Controller
{
    /**
     * GET /api/v1/common/locations
     *
     * Query parameters are validated by FetchLocationRequest:
     *   country  ISO code (USA, CAN) - narrows to that country
     *   format   `list` (default) or `map`
     *
     * `list` is canonical and mirrors the config:
     *
     *   [{ name, code, currency, phone_code, flag,
     *      states: [{ name, code, cities: [] }] }]
     *
     * `map` is the same data keyed by code, which is the shape the partner
     * portal's address components already expect:
     *
     *   { USA: { name, code, ..., states: { AL: { name, code, cities: [] } } } }
     *
     * Both are offered because the two portals grew different local copies. One
     * endpoint serving both means neither had to be rewritten to adopt it.
     */
    public function index(FetchLocationRequest $request): JsonResponse
    {
        $countries = Locations::all();

        if ($code = $request->validated('country')) {
            $countries = array_values(array_filter(
                $countries,
                fn ($country) => strcasecmp($country['code'] ?? '', $code) === 0
            ));
        }

        // Shaped by the resource in both cases - `map` is a re-keying of the
        // same resolved output, not a second shape maintained by hand.
        $list = LocationResource::collection($countries)->resolve($request);

        $payload = $request->validated('format') === 'map'
            ? $this->asMap($list)
            : $list;

        // Reference data that only changes on deploy, and the largest static
        // payload either portal pulls. The ETag is checked here rather than just
        // advertised: response()->json() does not handle conditional requests on
        // its own, so without this the header would be sent and never honoured.
        $etag = '"' . md5(json_encode($payload)) . '"';
        $headers = [
            'Cache-Control' => 'public, max-age=3600',
            'ETag' => $etag,
        ];

        if (trim((string) $request->headers->get('If-None-Match')) === $etag) {
            return response()->json(null, 304)->withHeaders($headers);
        }

        return ApiResponse::success($payload, __('common.locations_retrieved'))
            ->withHeaders($headers);
    }

    /**
     * Re-key resolved countries by country code, and their states by state code.
     */
    private function asMap(array $countries): array
    {
        $map = [];

        foreach ($countries as $country) {
            $states = [];

            foreach ($country['states'] ?? [] as $state) {
                $key = $state['code'] ?? $state['name'] ?? null;

                if ($key !== null) {
                    $states[$key] = $state;
                }
            }

            $country['states'] = $states;
            $map[$country['code'] ?? $country['name']] = $country;
        }

        return $map;
    }
}
