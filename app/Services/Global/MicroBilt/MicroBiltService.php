<?php

namespace App\Services\Global\MicroBilt;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Exception;

use App\Models\Common\AppConfiguration;

class MicroBiltService
{
    protected $baseUrl;
    protected $clientId;
    protected $clientSecret;
    protected $mode;

    public function __construct()
    {
        $this->mode = AppConfiguration::get('microbilt_mode', 'sandbox');
        $suffix = ($this->mode === 'production') ? '_production' : '_sandbox';

        $this->baseUrl = AppConfiguration::get('microbilt_base_url' . $suffix);
        $this->clientId = AppConfiguration::get('microbilt_client_id' . $suffix);
        $this->clientSecret = AppConfiguration::get('microbilt_client_secret' . $suffix);

        Log::info('MicroBilt Service Initialized', [
            'mode' => $this->mode,
            'base_url_key' => 'microbilt_base_url' . $suffix,
            'base_url' => $this->baseUrl,
            'client_id' => $this->clientId
        ]);
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
        // Use a unique cache key that accounts for the mode and the specific credentials.
        // If you change a key in the database, this hash will change, forcing a fresh authentication.
        $credentialsHash = md5($this->clientId . $this->clientSecret);
        $cacheKey = "microbilt_token_{$this->mode}_{$credentialsHash}";

        if (Cache::has($cacheKey)) {
            return Cache::get($cacheKey);
        }

        try {
            // Never the secret: this line lands in the application log.
            Log::info('MicroBilt Auth Attempt', [
                'mode' => $this->mode,
                'client_id' => $this->clientId,
                'base_url' => $this->baseUrl
            ]);

            $response = Http::asForm()->post($this->baseUrl . 'OAuth/Token', [
                'grant_type' => 'client_credentials',
                'client_id' => $this->clientId,
                'client_secret' => $this->clientSecret,
            ]);

            if ($response->successful()) {
                $data = $response->json();
                $token = $data['access_token'];
                $expiresIn = $data['expires_in'] ?? 3600;

                // Cache the token slightly less than expiry to avoid edge cases
                Cache::put($cacheKey, $token, $expiresIn - 60);

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
