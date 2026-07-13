<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Partner\Partner;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class DomainResolverController extends Controller
{
    private array $reserved = [];

    public function __construct()
    {
        $this->reserved = (array) config('domains.reserved', []);
    }

    public function resolve(Request $request)
    {
        $domainHeader = $request->header('X-Domain');
        $internalKeyHeader = $request->header('X-Internal-Key');

        Log::info('[DomainResolver:resolve] Start resolving domain.', [
            'x-domain' => $domainHeader,
            'x-internal-key-received' => $internalKeyHeader,
            'expected-internal-key' => config('domains.internal_key'),
        ]);

        if ($internalKeyHeader !== config('domains.internal_key')) {
            Log::warning('[DomainResolver:resolve] Internal key mismatch.', [
                'received' => $internalKeyHeader,
                'expected' => config('domains.internal_key'),
            ]);
            return response('', 401);
        }

        $domain = strtolower(trim($request->header('X-Domain')));
        $domain = preg_replace('/^www\./', '', $domain);

        Log::info('[DomainResolver:resolve] Normalized domain to resolve.', ['domain' => $domain]);

        if (in_array(strtolower($domain), array_map('strtolower', $this->reserved))) {
            Log::warning('[DomainResolver:resolve] Domain is reserved.', ['domain' => $domain, 'reserved' => $this->reserved]);
            return response('', 403);
        }

        $cacheKey = "domain_resolve:{$domain}";

        try {
            $result = Cache::remember($cacheKey, 0, function () use ($domain) {
                Log::info('[DomainResolver:resolve] Cache miss or cache bypass. Querying database.', ['domain' => $domain]);
                $partner = Partner::where('partner_domain', $domain)
                    ->orWhere('partner_subdomain', $domain)
                    ->select('id', 'active_custom_url')
                    ->first();

                // Only active 
                // if(!$partner->active_custom_url) {    
                //     return null;
                // }

                if ($partner) {
                    Log::info('[DomainResolver:resolve] Found partner match.', ['partner_id' => $partner->id]);
                    return ['type' => 'partner', 'partner_id' => $partner->id];
                }

                $borrower = Partner::where('borrower_domain', $domain)
                    ->select('id')
                    ->first();

                if ($borrower) {
                    Log::info('[DomainResolver:resolve] Found borrower match.', ['partner_id' => $borrower->id]);
                    return ['type' => 'borrower', 'partner_id' => $borrower->id];
                }

                Log::info('[DomainResolver:resolve] No match found in database.', ['domain' => $domain]);
                return null;
            });

            if (!$result) {
                Log::warning('[DomainResolver:resolve] Domain not found, returning 404.', ['domain' => $domain]);
                return response('', 404);
            }

            Log::info('[DomainResolver:resolve] Success.', [
                'domain' => $domain,
                'tenant-type' => $result['type'],
                'partner-id' => $result['partner_id']
            ]);

            return response('', 200, [
                'X-Tenant-Type' => $result['type'],
                'X-Partner-Id' => (string) $result['partner_id'],
            ]);
        } catch (\Throwable $e) {
            Log::error('[DomainResolver:resolve] Exception during resolution: ' . $e->getMessage(), [
                'exception' => $e,
                'domain' => $domain
            ]);
            // Fail-open: return 200 to let the request proceed (better than a 500 outage)
            return response('', 200, [
                'X-Tenant-Type' => 'error',
                'X-Partner-Id' => '0',
            ]);
        }
    }

    public function caddyCheck(Request $request)
    {
        $rawDomain = $request->query('domain');
        $domain = strtolower(trim($rawDomain));

        Log::info('[DomainResolver:caddyCheck] Start check.', [
            'raw_domain' => $rawDomain,
            'normalized_domain' => $domain
        ]);

        if (!$domain) {
            Log::warning('[DomainResolver:caddyCheck] Empty domain query parameter.');
            return response('Bad Request', 400);
        }

        $domain = preg_replace('/^www\./', '', $domain);

        $reserved = array_map('strtolower', (array) config('domains.reserved', []));
        if (in_array($domain, $reserved)) {
            Log::warning('[DomainResolver:caddyCheck] Domain is reserved.', ['domain' => $domain, 'reserved' => $reserved]);
            return response('Forbidden', 403);
        }

        $baseWebsite = config('app.base_website');
        Log::info('[DomainResolver:caddyCheck] Base website config.', [
            'base_website' => $baseWebsite,
            'check_ends_with' => '.' . $baseWebsite
        ]);

        if (str_ends_with($domain, '.' . $baseWebsite)) {
            Log::info('[DomainResolver:caddyCheck] Domain matches base website subdomain check, returning OK.', ['domain' => $domain]);
            return response('OK', 200);
        }

        Log::info('[DomainResolver:caddyCheck] Querying database for domain existence.', ['domain' => $domain]);
        $exists = Partner::where('partner_domain', $domain)
            ->orWhere('partner_subdomain', $domain)
            ->orWhere('borrower_domain', $domain)
            ->first();

        if(!$exists) {
            Log::warning('[DomainResolver:caddyCheck] Domain not found in database.', ['domain' => $domain]);
            return response('Forbidden', 403);
        }

        // Only active custom urls are allowed
        // if(!$exists->active_custom_url) {
        //     return response('Forbidden', 403);
        // }

        if ($exists) {
            Log::info('[DomainResolver:caddyCheck] Found matching partner in database, returning OK.', [
                'partner_id' => $exists->id,
                'domain' => $domain
            ]);
            return response('OK', 200);
        } else {
            Log::warning('[DomainResolver:caddyCheck] Falling back to Forbidden.', ['domain' => $domain]);
            return response('Forbidden', 403);
        }
    }
}