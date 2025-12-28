<?php

namespace App\Services\Global\MicroBilt;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use App\Models\Borrower\Borrower;
use App\Models\CreditBuilder\BorrowerTaskVerificationRequest;
use App\Models\CreditBuilder\TierTask;
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
            $response = Http::asForm()->post($this->baseUrl . 'oauth/token', [ // Adjust endpoint as per specific MicroBilt API docs
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

    /**
     * Verify Phone Name.
     *
     * @param string $phone
     * @param string $firstName
     * @param string $lastName
     * @param string $middleName
     * @return array
     */
    public function verifyPhoneName(string $phone, string $firstName, string $lastName, string $middleName = ' ')
    {
        return $this->sendRequest('PhoneNameVerification', [
            'Name' => [
                'FirstName' => $firstName,
                'MiddleName' => $middleName,
                'LastName' => $lastName,
            ],
            'PhoneNumber' => $phone,
        ]);
    }
}
