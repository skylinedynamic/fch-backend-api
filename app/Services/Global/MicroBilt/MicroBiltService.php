<?php

namespace App\Services\Global\MicroBilt;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use App\Models\Borrower\Borrower;
use App\Models\CreditBuilder\BorrowerTierTask;
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
     * Get a credit report for a borrower.
     *
     * @param array $borrowerData
     * @return array
     * @throws Exception
     */
    public function getReport(array $borrowerData)
    {
        $token = $this->authenticate();

        try {
            // Using Laravel's retry helper for robust transient error handling
            $response = retry(3, function () use ($token, $borrowerData) {
                return Http::withToken($token)
                    ->timeout(30) // Set a reasonable timeout
                    ->post($this->baseUrl . 'ibv/report', $borrowerData); // Adjust endpoint based on specific product (e.g., IBV, Credit, etc.)
            }, 100);

            if ($response->successful()) {
                return $response->json();
            }

            Log::error('MicroBilt Report Failed', ['status' => $response->status(), 'body' => $response->body()]);
            throw new Exception('MicroBilt API Error: ' . $response->status());

        } catch (\Throwable $e) {
            Log::error('MicroBilt Report Exception', ['message' => $e->getMessage()]);
            throw $e;
        }
    }

    /**
     * Map MicroBilt response to Tiers and Workflows.
     * 
     * @param array $response
     * @param int $borrowerId
     * @return void
     */
    public function mapResponseToTiers(array $response, int $borrowerId)
    {
        // Example Mapping Logic
        // This needs to be customized based on the actual MicroBilt response structure
        // and your specific Tier Task rules.
        
        $borrower = Borrower::find($borrowerId);
        if (!$borrower) {
            Log::warning("Borrower not found for ID: $borrowerId during mapping.");
            return;
        }

        // Example: If a certain score is met, mark a task as complete
        // response structure is hypothetical here
        $score = $response['decision']['score'] ?? null;
        
        if ($score && $score > 700) {
           // Find a relevant task. For example, "Achieve 700+ Credit Score"
           // This requires knowing your TierTask IDs or codes.
           // Let's assume a hypothetical TierTask ID or lookup by title
           
           $task = TierTask::where('title', 'like', '%700+ Credit Score%')->first();
           
           if ($task) {
               BorrowerTierTask::updateOrCreate(
                   [
                       'borrower_id' => $borrowerId,
                       'tier_id' => $task->tier_id,
                       'tier_task_id' => $task->id,
                   ],
                   [
                       'status' => 'completed',
                       'completed_at' => now(),
                   ]
               );
               Log::info("Marked task {$task->id} as complete for borrower $borrowerId based on MicroBilt score.");
           }
        }
    }

    /**
     * Verify a business phone number.
     *
     * @param string $phone
     * @return array
     */
    public function verifyPhone(string $phone)
    {
        return $this->getReport(['phone' => $phone, 'product_type' => 'enhanced_phone_search']);
    }

    /**
     * Verify a business address.
     *
     * @param array $addressData
     * @return array
     */
    public function verifyAddress(array $addressData)
    {
        return $this->getReport(array_merge($addressData, ['product_type' => 'address_standardization']));
    }

    /**
     * Verify EIN / Business Identity.
     *
     * @param string $ein
     * @param string $businessName
     * @return array
     */
    public function verifyEIN(string $ein, string $businessName)
    {
        return $this->getReport(['tin' => $ein, 'company_name' => $businessName, 'product_type' => 'business_identity_verification']);
    }
}
