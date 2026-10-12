<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Partner\Partner;
use Illuminate\Http\Request;
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
        $internalKeyHeader = (string) $request->header('X-Internal-Key', '');
        $internalKey = (string) config('domains.internal_key', '');

        // Fails closed: no key configured means no lookup, and the comparison is
        // constant-time so the key cannot be guessed a character at a time.
        if ($internalKey === '' || !hash_equals($internalKey, $internalKeyHeader)) {
            Log::warning('[DomainResolver] Invalid internal key attempt.', [
                'x-domain' => $request->header('X-Domain'),
            ]);
            return response('', 401);
        }

        $domain = strtolower(trim($request->header('X-Domain')));
        $domain = rtrim($domain, '.');                   // Remove trailing DNS dot if present
        $domain = preg_replace('/^www\./', '', $domain);

        if (in_array(strtolower($domain), array_map('strtolower', $this->reserved))) {
            return response('', 403);
        }

        try {
            // Check partner_subdomain (always works regardless of active_custom_url)
            $partner = Partner::where('partner_subdomain', $domain)->select('id')->first();

            if ($partner) {
                return response('', 200, [
                    'X-Tenant-Type' => 'partner',
                    'X-Partner-Id'  => (string) $partner->id,
                ]);
            }

            // Check custom domain — only if active_custom_url is enabled
            $partner = Partner::where('partner_domain', $domain)
                ->where('active_custom_url', true)
                ->select('id')
                ->first();

            if ($partner) {
                Log::info('[DomainResolver] Tenant resolved via custom domain.', [
                    'domain'     => $domain,
                    'partner-id' => $partner->id,
                ]);
                return response('', 200, [
                    'X-Tenant-Type' => 'partner',
                    'X-Partner-Id'  => (string) $partner->id,
                ]);
            }

            // Check borrower domain — only if active_custom_url is enabled
            $borrower = Partner::where('borrower_domain', $domain)
                ->where('active_custom_url', true)
                ->select('id')
                ->first();

            if ($borrower) {
                return response('', 200, [
                    'X-Tenant-Type' => 'borrower',
                    'X-Partner-Id'  => (string) $borrower->id,
                ]);
            }

            return response('', 404);
        } catch (\Throwable $e) {
            Log::error('[DomainResolver] Exception during tenant resolution.', [
                'domain' => $domain,
                'error'  => $e->getMessage(),
            ]);
            // Fail-open: return 200 to let the request proceed (better than a 500 outage)
            return response('', 200, [
                'X-Tenant-Type' => 'error',
                'X-Partner-Id'  => '0',
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

        $domain = rtrim($domain, '.');                   // Remove trailing DNS dot if present
        $domain = preg_replace('/^www\./', '', $domain);

        $reserved = array_map('strtolower', (array) config('domains.reserved', []));
        if (in_array($domain, $reserved)) {
            return response('Forbidden', 403);
        }

        $baseWebsite = config('app.base_website');

        // Whitelist main system domains — skip DB lookup
        $systemSubdomains = [
            $baseWebsite,
            'www.' . $baseWebsite,
            'api.' . $baseWebsite,
            'partner.' . $baseWebsite,
            'app.' . $baseWebsite,
        ];

        if (in_array($domain, $systemSubdomains) || str_starts_with($domain, 'api.') || str_starts_with($domain, 'partner.') || str_starts_with($domain, 'app.')) {
            return response('OK', 200);
        }

        // Check if it is a valid partner subdomain
        $isSubdomain = Partner::where('partner_subdomain', $domain)->exists();

        if ($isSubdomain) {
            return response('OK', 200);
        }

        // Custom domain — only allow SSL if active_custom_url is enabled
        $exists = Partner::where('partner_domain', $domain)
            ->where('active_custom_url', true)
            ->exists()
            || Partner::where('borrower_domain', $domain)
            ->where('active_custom_url', true)
            ->exists();

        if (!$exists) {
            return response('Forbidden', 403);
        }

        Log::info('[DomainResolver] SSL approved for custom domain.', [
            'domain' => $domain,
        ]);

        return response('OK', 200);
    }
}