<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Partner\Partner;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class DomainResolverController extends Controller
{
    // Reserved domains — always blocked from being registered
    private array $reserved = [];

    public function __construct()
    {
        $this->reserved = config('domains.reserved');
    }

    public function resolve(Request $request)
    {
        // Block public access
        if ($request->header('X-Internal-Key') !== config('domains.internal_key')) {
            return response()->noContent(401);
        }

        $domain = strtolower(trim($request->header('X-Domain')));

        $domain = preg_replace('/^www\./', '', $domain);

        // Block reserved domains from going through resolver
        if (in_array(strtolower($domain), array_map('strtolower', $this->reserved))) {
            return response()->noContent(403);
        }

        $cacheKey = "domain_resolve:{$domain}";

        $result = Cache::remember($cacheKey, now()->addMinutes(5), function () use ($domain) {

        // =========================
        // PARTNER CUSTOM DOMAIN
        // =========================

        $partner = Partner::where('partner_domain', $domain)
            ->select('id')
            ->first();

        if ($partner) {
            return [
                'type' => 'partner',
                'partner_id' => $partner->id
            ];
        }

        // =========================
        // BORROWER CUSTOM DOMAIN
        // =========================

        $borrower = Partner::where('borrower_domain', $domain)
            ->select('id')
            ->first();

        if ($borrower) {
            return [
                'type' => 'borrower',
                'partner_id' => $borrower->id
            ];
        }

            return null;
        });

        if (!$result) {
            return response()->noContent(404);
        }

        return response()->noContent(200)
            ->withHeaders([
                'X-Tenant-Type' => $result['type'],
                'X-Partner-Id' => $result['partner_id'],
            ]);
    }

    public function caddyCheck(Request $request)
    {
        $domain = $request->query('domain');

        $exists = Partner::where('partner_domain', $domain)
            ->orWhere('borrower_domain', $domain)
            ->orWhere('partner_domain', 'LIKE', "%.skylinedynamic.dev")
            ->exists();

        return $exists
            ? response('OK', 200)
            : response('Forbidden', 403);
    }
}