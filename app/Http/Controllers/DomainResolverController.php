<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Partner\Partner;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

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

        if ($internalKeyHeader !== config('domains.internal_key')) {
            return response('', 401);
        }

        $domain = strtolower(trim($domainHeader));
        $domain = preg_replace('/^www\./', '', $domain);

        if (in_array(strtolower($domain), array_map('strtolower', $this->reserved))) {
            return response('', 403);
        }

        $cacheKey = "domain_resolve:{$domain}";

        try {
            $result = Cache::remember($cacheKey, now()->addMinutes(5), function () use ($domain) {
                $partner = Partner::where('partner_domain', $domain)
                    ->select('id')
                    ->first();

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

            return response('', 200, [
                'X-Tenant-Type' => $result['type'],
                'X-Partner-Id' => (string) $result['partner_id'],
            ]);
        } catch (\Throwable $e) {
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
        if (str_ends_with($domain, '.' . $baseWebsite)) {
            return response('OK', 200);
        }

        $exists = Partner::where('partner_domain', $domain)
            ->orWhere('borrower_domain', $domain)
            ->exists();

        if ($exists) {
            return response('OK', 200);
        } else {
            return response('Forbidden', 403);
        }
    }
}