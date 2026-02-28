<?php

namespace App\Services\Global\MicroBilt;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Exception;

class MicroBiltService
{
    protected $baseUrl;
    protected $clientId;
    protected $clientSecret;

    public function __construct()
    {
        $this->baseUrl = config('services.microbilt.base_url');
        $this->clientId = config('services.microbilt.client_id');
        $this->clientSecret = config('services.microbilt.client_secret');
    }

    /**
     * Authenticate with MicroBilt and get an access token.
     * Uses caching to store the token until it expires.
     *
     * @return string
     * @throws Exception
     */
    public function authenticate()
    {
        if (Cache::has('microbilt_token')) {
            return Cache::get('microbilt_token');
        }

        try {
            Log::info('MicroBilt Auth Attempt Credentials', [
                'client_id' => $this->clientId,
                'client_secret' => $this->clientSecret,
                'base_url' => $this->baseUrl
            ]);

            $response = Http::asForm()->post($this->baseUrl . 'OAuth/Token', [ // Adjust endpoint as per specific MicroBilt API docs
                'grant_type' => 'client_credentials',
                'client_id' => $this->clientId,
                'client_secret' => $this->clientSecret,
            ]);

            if ($response->successful()) {
                $data = $response->json();
                $token = $data['access_token'];
                $expiresIn = $data['expires_in'] ?? 3600;

                // Cache the token slightly less than expiry to avoid edge cases
                Cache::put('microbilt_token', $token, $expiresIn - 60);

                return $token;
            }

            Log::error('MicroBilt Auth Failed', ['response' => $response->body()]);
            throw new Exception('Failed to authenticate with MicroBilt: ' . $response->status());

        } catch (\Throwable $e) {
            Log::error('MicroBilt Auth Exception', ['message' => $e->getMessage()]);
            throw $e;
        }
    }

    /**
     * Send a request to MicroBilt API.
     * 
     * @param string $endpoint
     * @param array $data
     * @return array
     * @throws Exception
     */
    public function sendRequest(string $endpoint, array $data)
    {
        $token = $this->authenticate();

        try {
            // Using Laravel's retry helper for robust transient error handling
            $response = retry(3, function () use ($token, $endpoint, $data) {
                return Http::withToken($token)
                    ->timeout(30)
                    ->post($this->baseUrl . $endpoint, $data);
            }, 100);

            if ($response->successful()) {
                return $response->json();
            }

            Log::error('MicroBilt API Failed', [
                'endpoint' => $endpoint, 
                'status' => $response->status(), 
                'body' => $response->body()
            ]);
            
            throw new Exception('MicroBilt API Error: ' . $response->status());

        } catch (\Throwable $e) {
            Log::error('MicroBilt API Exception', [
                'endpoint' => $endpoint, 
                'message' => $e->getMessage()
            ]);
            throw $e;
        }
    }


    public function einVerification(string $companyName, string $ein)
    {
        return $this->sendRequest('MBBusinessReport/GetReport', [
            'MsgRqHdr' => [
                'ProductID' => 'BUSRVTXID'
            ],
            'CorporateDetails' => [
                'CompanyName' => $companyName,
            ],
            'FEIN' => $ein,
        ]);
    }

    public function businessAddressVerification(string $companyName, string $address, string $city, string $state, string $postalCode)
    {
        return $this->sendRequest('MBBusinessReport/GetReport', [
            'MsgRqHdr' => [
                'ProductID' => 'BUSSRCH'
            ],
            'CorporateDetails' => [
                'CompanyName' => $companyName,
                'CorporateAddress' => [
                    [
                        'Addr1' => $address,
                        'City' => $city,
                        'StateProv' => $state,
                        'PostalCode' => $postalCode,
                    ]
                ]
            ]
        ]);
    }

    public function businessPhoneVerification(string $companyName, string $phone)
    {
        return $this->sendRequest('MBBusinessReport/GetReport', [
            'MsgRqHdr' => [
                'ProductID' => 'BUSSRCH'
            ],
            'CorporateDetails' => [
                'CompanyName' => $companyName,
                'ContactInfo' => [
                    'Phone' => [
                        'PhoneInfo' => [
                            'PhoneNumber' => $phone
                        ]
                    ]
                ]
            ]
        ]);
    }

    public function businessIdentityVerification(string $companyName, string $ein, string $city, string $state)
    {
        return $this->sendRequest('MBBusinessReport/GetReport', [
            'MsgRqHdr' => [
                'ProductID' => 'BUSSRCH'
            ],
            'CorporateDetails' => [
                'CompanyName' => $companyName,
                'CorporateAddress' => [
                    [
                        'City' => $city,
                        'StateProv' => $state,
                    ]
                ]
            ],
            'FEIN' => $ein,
        ]);
    }

    public function activeLegalEntityVerification(string $companyName, string $ein)
    {
        return $this->sendRequest('MBBusinessReport/GetReport', [
            'MsgRqHdr' => [
                'ProductID' => 'BUSSOS'
            ],
            'CorporateDetails' => [
                'CompanyName' => $companyName,
            ],
            'FEIN' => $ein,
        ]);
    }
}
