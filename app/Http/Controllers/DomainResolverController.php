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

        Log::info('[DomainResolver] resolve() called', [
            'raw_domain' => $domainHeader,
            'has_internal_key' => !empty($internalKeyHeader),
        ]);

        if ($internalKeyHeader !== config('domains.internal_key')) {
            Log::warning('[DomainResolver] resolve() Unauthorized: X-Internal-Key mismatch');
            return response('', 401);
        }

        $domain = strtolower(trim($domainHeader));
        $domain = preg_replace('/^www\./', '', $domain);

        Log::info('[DomainResolver] resolve() Cleaned domain', ['domain' => $domain]);

        if (in_array(strtolower($domain), array_map('strtolower', $this->reserved))) {
            Log::warning('[DomainResolver] resolve() Forbidden: Reserved domain', ['domain' => $domain]);
            return response('', 403);
        }

        $cacheKey = "domain_resolve:{$domain}";

        try {
            $isCacheMiss = false;
            $result = Cache::remember($cacheKey, now()->addMinutes(5), function () use ($domain, &$isCacheMiss) {
                $isCacheMiss = true;
                Log::info('[DomainResolver] Cache miss. Searching DB for domain', ['domain' => $domain]);

                $partner = Partner::where('partner_domain', $domain)
                    ->select('id')
                    ->first();

                if ($partner) {
                    Log::info('[DomainResolver] Match found: Partner domain', ['domain' => $domain, 'partner_id' => $partner->id]);
                    return ['type' => 'partner', 'partner_id' => $partner->id];
                }

                $borrower = Partner::where('borrower_domain', $domain)
                    ->select('id')
                    ->first();

                if ($borrower) {
                    Log::info('[DomainResolver] Match found: Borrower domain', ['domain' => $domain, 'partner_id' => $borrower->id]);
                    return ['type' => 'borrower', 'partner_id' => $borrower->id];
                }

                Log::warning('[DomainResolver] Match not found in DB', ['domain' => $domain]);
                return null;
            });

            if (!$isCacheMiss) {
                Log::info('[DomainResolver] Cache hit', ['domain' => $domain, 'result' => $result]);
            }

            if (!$result) {
                Log::warning('[DomainResolver] Resolution 404 (Not Found) returned', ['domain' => $domain]);
                return response('', 404);
            }

            Log::info('[DomainResolver] Resolution 200 (Success) returned', [
                'domain' => $domain,
                'type' => $result['type'],
                'partner_id' => $result['partner_id'],
            ]);

            return response('', 200, [
                'X-Tenant-Type' => $result['type'],
                'X-Partner-Id' => (string) $result['partner_id'],
            ]);
        } catch (\Throwable $e) {
            Log::error('[DomainResolver] Failure: ' . $e->getMessage(), [
                'domain' => $domain,
                'trace' => $e->getTraceAsString(),
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
        Log::info('[DomainResolver] caddyCheck() called', ['raw_domain' => $rawDomain]);

        $domain = strtolower(trim($rawDomain));

        if (!$domain) {
            Log::warning('[DomainResolver] caddyCheck() Bad Request: empty domain parameter');
            return response('Bad Request', 400);
        }

        $domain = preg_replace('/^www\./', '', $domain);
        Log::info('[DomainResolver] caddyCheck() Cleaned domain', ['domain' => $domain]);

        $reserved = array_map('strtolower', (array) config('domains.reserved', []));
        if (in_array($domain, $reserved)) {
            Log::warning('[DomainResolver] caddyCheck() Forbidden: Reserved domain', ['domain' => $domain]);
            return response('Forbidden', 403);
        }

        $baseWebsite = config('app.base_website');
        if (str_ends_with($domain, '.' . $baseWebsite)) {
            Log::info('[DomainResolver] caddyCheck() OK: Subdomain matches base website', [
                'domain' => $domain,
                'base_website' => $baseWebsite,
            ]);
            return response('OK', 200);
        }

        Log::info('[DomainResolver] caddyCheck() Querying DB for domain existence', ['domain' => $domain]);
        $exists = Partner::where('partner_domain', $domain)
            ->orWhere('borrower_domain', $domain)
            ->exists();

        if ($exists) {
            Log::info('[DomainResolver] caddyCheck() OK: Domain found in DB', ['domain' => $domain]);
            return response('OK', 200);
        } else {
            Log::warning('[DomainResolver] caddyCheck() Forbidden: Domain not found in DB', ['domain' => $domain]);
            return response('Forbidden', 403);
        }
    }
}