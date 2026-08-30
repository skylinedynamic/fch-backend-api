<?php

namespace App\Services\Global\OpenCorporates;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Exception;

class OpenCorporatesService
{
    protected $baseUrl = 'https://api.opencorporates.com/v0.4';
    protected $apiToken;

    public function __construct()
    {
        $this->apiToken = config('services.opencorporates.api_token');
    }

    /**
     * Search for a company by name and jurisdiction.
     *
     * @param string $name
     * @param string $jurisdiction (e.g., 'us_de' for Delaware, 'us_ca' for California)
     * @return array
     * @throws Exception
     */
    public function searchCompany(string $name, string $jurisdiction = null)
    {
        try {
            $params = [
                'q' => $name,
                'api_token' => $this->apiToken,
            ];

            if ($jurisdiction) {
                $params['jurisdiction_code'] = $jurisdiction;
            }

            $response = Http::get($this->baseUrl . '/companies/search', $params);

            if ($response->successful()) {
                return $response->json();
            }

            Log::error('OpenCorporates Search Failed', ['status' => $response->status(), 'body' => $response->body()]);
            throw new Exception('OpenCorporates API Error: ' . $response->status());

        } catch (\Throwable $e) {
            Log::error('OpenCorporates Search Exception', ['message' => $e->getMessage()]);
            throw $e;
        }
    }

    /**
     * Get details for a specific company number and jurisdiction.
     *
     * @param string $companyNumber
     * @param string $jurisdictionCode
     * @return array
     * @throws Exception
     */
    public function getCompany(string $companyNumber, string $jurisdictionCode)
    {
        try {
            $response = Http::get($this->baseUrl . "/companies/$jurisdictionCode/$companyNumber", [
                'api_token' => $this->apiToken,
            ]);

            if ($response->successful()) {
                return $response->json();
            }

            Log::error('OpenCorporates Get Company Failed', ['status' => $response->status(), 'body' => $response->body()]);
            throw new Exception('OpenCorporates API Error: ' . $response->status());

        } catch (\Throwable $e) {
            Log::error('OpenCorporates Get Company Exception', ['message' => $e->getMessage()]);
            throw $e;
        }
    }
}
