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
        $internalKeyHeader = $request->header('X-Internal-Key');

        if ($internalKeyHeader !== config('domains.internal_key')) {
            Log::warning('[DomainResolver] Invalid internal key attempt.', [
                'x-domain' => $request->header('X-Domain'),
            ]);
            return response('', 401);
        }

        $domain = strtolower(trim($request->header('X-Domain')));
        $domain = preg_replace('/^www\./', '', $domain);

        if (in_array(strtolower($domain), array_map('strtolower', $this->reserved))) {
            return response('', 403);
        }

        $cacheKey = "domain_resolve:{$domain}";

        try {
            $result = Cache::remember($cacheKey, 0, function () use ($domain) {
                $partner = Partner::where('partner_domain', $domain)
                    ->orWhere('partner_subdomain', $domain)
                    ->select('id', 'active_custom_url')
                    ->first();

                // Only active 
                // if(!$partner->active_custom_url) {    
                //     return null;
                // }

                if ($partner) {
                    return ['type' => 'partner', 'partner_id' => $partner->id];
                }

                $borrower = Partner::where('borrower_domain', $domain)
                    ->select('id')
                    ->first();

                if ($borrower) {
                    return ['type' => 'borrower', 'partner_id' => $borrower->id];
                }

                return null;
            });

            if (!$result) {
                return response('', 404);
            }

            Log::info('[DomainResolver] Tenant resolved successfully.', [
                'domain'      => $domain,
                'tenant-type' => $result['type'],
                'partner-id'  => $result['partner_id'],
            ]);

            return response('', 200, [
                'X-Tenant-Type' => $result['type'],
                'X-Partner-Id' => (string) $result['partner_id'],
            ]);
        } catch (\Throwable $e) {
            Log::error('[DomainResolver] Exception during tenant resolution.', [
                'domain'  => $domain,
                'error'   => $e->getMessage(),
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

        if (!$domain) {
            return response('Bad Request', 400);
        }

        $domain = preg_replace('/^www\./', '', $domain);

        $reserved = array_map('strtolower', (array) config('domains.reserved', []));
        if (in_array($domain, $reserved)) {
            return response('Forbidden', 403);
        }

        $baseWebsite = config('app.base_website');

        // Whitelist main system domains to skip database checks if queried
        $systemSubdomains = [
            $baseWebsite,
            'www.' . $baseWebsite,
            'api.' . $baseWebsite,
            'partner.' . $baseWebsite,
            'app.' . $baseWebsite,
        ];

        if (in_array($domain, $systemSubdomains)) {
            return response('OK', 200);
        }

        $exists = Partner::where('partner_domain', $domain)
            ->orWhere('partner_subdomain', $domain)
            ->orWhere('borrower_domain', $domain)
            ->first();

        if (!$exists) {
            return response('Forbidden', 403);
        }

        // Only active custom urls are allowed
        // if(!$exists->active_custom_url) {
        //     return response('Forbidden', 403);
        // }

        Log::info('[DomainResolver] SSL approved for custom domain.', [
            'domain'     => $domain,
            'partner_id' => $exists->id,
        ]);

        return response('OK', 200);
    }
}