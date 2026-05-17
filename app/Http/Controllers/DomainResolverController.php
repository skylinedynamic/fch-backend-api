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
        $this->reserved = config('domains.reserved');
    }

    public function resolve(Request $request)
    {
        // Disable session — prevents Set-Cookie headers on internal requests
        config(['session.driver' => 'array']);

        if ($request->header('X-Internal-Key') !== config('domains.internal_key')) {
            return response('', 401);
        }

        $domain = strtolower(trim($request->header('X-Domain')));
        $domain = preg_replace('/^www\./', '', $domain);

        if (in_array(strtolower($domain), array_map('strtolower', $this->reserved))) {
            return response('', 403);
        }

        $cacheKey = "domain_resolve:{$domain}";

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

        $accelPath = $result['type'] === 'partner' ? '/internal/partner' : '/internal/borrower';

        return response('', 200, [
            'X-Accel-Redirect' => $accelPath,
            'X-Tenant-Type' => $result['type'],
            'X-Partner-Id' => (string) $result['partner_id'],
        ]);
    }

    public function caddyCheck(Request $request)
    {
        // Disable session — prevents Set-Cookie headers
        config(['session.driver' => 'array']);

        $domain = strtolower(trim($request->query('domain')));

        if (!$domain) {
            return response('Bad Request', 400);
        }

        $domain = preg_replace('/^www\./', '', $domain);

        $reserved = array_map('strtolower', config('domains.reserved'));
        if (in_array($domain, $reserved)) {
            return response('Forbidden', 403);
        }

        // Allow any *.skylinedynamic.dev — certified partners
        if (str_ends_with($domain, '.skylinedynamic.dev')) {
            return response('OK', 200);
        }

        // Allow only registered WL custom domains
        $exists = Partner::where('partner_domain', $domain)
            ->orWhere('borrower_domain', $domain)
            ->exists();

        return $exists
            ? response('OK', 200)
            : response('Forbidden', 403);
    }
}