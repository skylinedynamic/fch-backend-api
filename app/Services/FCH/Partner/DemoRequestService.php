<?php

namespace App\Services\FCH\Partner;

use App\Models\DemoRequest;
use Illuminate\Http\Request;

class DemoRequestService
{
    public function getAllDemoRequests(Request $request)
    {
        $query = DemoRequest::query();

        if ($search = $request->get('search')) {
            $search = trim($search);
            $query->where(function ($q) use ($search) {
                $q->where('customer_name', 'LIKE', "%{$search}%")
                  ->orWhere('company_name', 'LIKE', "%{$search}%")
                  ->orWhere('phone_number', 'LIKE', "%{$search}%")
                  ->orWhere('email', 'LIKE', "%{$search}%")
                  ->orWhere('assign_to', 'LIKE', "%{$search}%")
                  ->orWhere('status', 'LIKE', "%{$search}%");
            });
        }

        if ($request->filled('status') && $request->status !== 'All') {
            $query->where('status', $request->status);
        }

        if ($request->filled('assign_to') && $request->assign_to !== 'All') {
            $query->where('assign_to', $request->assign_to);
        }

        $demoRequests = $query->orderByDesc('id')->paginate($request->get('per_page', 2));

        return [
            'data' => $demoRequests->items(),
            'meta' => [
                'current_page'   => $demoRequests->currentPage(),
                'from'           => $demoRequests->firstItem(),
                'to'             => $demoRequests->lastItem(),
                'per_page'       => $demoRequests->perPage(),
                'total'          => $demoRequests->total(),
                'last_page'      => $demoRequests->lastPage(),
                'next_page_url'  => $demoRequests->nextPageUrl(),
                'prev_page_url'  => $demoRequests->previousPageUrl(),
                'links'          => $demoRequests->linkCollection(),
            ],
        ];
    }
}
