<?php

namespace App\Services\FCH\Common\CreditBuilder;

use App\Traits\PaginatesApi;
use App\Models\Partner\Partner;
use App\Models\Borrower\Borrower;
use App\Models\CreditBuilder\Tier;
use Illuminate\Support\Facades\DB;
use App\Services\Global\Datatable\QueryFilter;
use App\Models\CreditBuilder\BorrowerTaskVerificationRequest;
use App\Http\Resources\FCH\CreditBuilder\BorrowerTaskVerificationRequestResource;

class VerificationCenterService
{
    use PaginatesApi;

    /**
     * Fetch all borrower task verification requests with related data.
     */
    public function fetchAll(array $filters)
    {
        $query = BorrowerTaskVerificationRequest::query();

        $authUser = auth()->user();

       
        // Apply partner security filter
        if ($authUser && $authUser->partner && $authUser->partner->is_host == 1) {
            // If user is a host, show all data by default, or filter by partner_id if provided
            if (!empty($filters['partner_id'])) {
                $query->where('partner_id', $filters['partner_id']);
            }
        } elseif ($authUser && $authUser->partner_id) {
            // If user is a standard partner, only show data from their own partner
            $query->where('partner_id', $authUser->partner_id);
        } elseif (!empty($filters['partner_id'])) {
            // If user has no partner (Admin), they can filter by partner_id if provided
            $query->where('partner_id', $filters['partner_id']);
        }
            
        $query->select(
                'borrower_id',
                'partner_id',
                'tier_id',
                DB::raw("COUNT(CASE WHEN status = '" . BorrowerTaskVerificationRequest::PENDING . "' THEN 1 END) as pending_count"),
                DB::raw('MAX(created_at) as request_date'),
                DB::raw("DATE_FORMAT(MAX(created_at), '%M %d, %Y %h:%i %p') as formatted_request_date"),
                DB::raw('MIN(id) as id')
            )
            ->addSelect([
                'borrower_name' => Borrower::select(DB::raw("CONCAT(first_name, ' ', last_name)"))
                    ->whereColumn('borrowers.id', 'borrower_task_verification_requests.borrower_id')
                    ->limit(1),
                'partner_name' => Partner::select('full_name')
                    ->whereColumn('partners.id', 'borrower_task_verification_requests.partner_id')
                    ->limit(1),
                'tier_name' => Tier::select('name')
                    ->whereColumn('tiers.id', 'borrower_task_verification_requests.tier_id')
                    ->limit(1),
            ])
            ->with(['borrower:id,first_name,last_name', 'partner:id,full_name', 'tier:id,name'])
            ->groupBy('borrower_id', 'partner_id', 'tier_id')
            ->having('pending_count', '>', 0);

        // Search and Filters using HAVING for grouped columns and subqueries
        $inputFilters = $filters['filters'] ?? [];

        if (!empty($inputFilters['borrower_name'])) {
            $val = is_array($inputFilters['borrower_name']) ? ($inputFilters['borrower_name']['value'] ?? null) : $inputFilters['borrower_name'];
            if ($val) {
                $query->having('borrower_name', 'LIKE', "%{$val}%");
            }
        }

        if (!empty($inputFilters['tier_name'])) {
            $val = is_array($inputFilters['tier_name']) ? ($inputFilters['tier_name']['value'] ?? null) : $inputFilters['tier_name'];
            if ($val) {
                $query->having('tier_name', 'LIKE', "%{$val}%");
            }
        }

        if (!empty($inputFilters['pending_count'])) {
            $val = is_array($inputFilters['pending_count']) ? ($inputFilters['pending_count']['value'] ?? null) : $inputFilters['pending_count'];
            if ($val !== null && $val !== '') {
                $query->having('pending_count', '=', (int)$val);
            }
        }

        if (!empty($filters['search'])) {
            $search = trim((string) $filters['search']);
            $query->having(function ($q) use ($search) {
                $q->having('borrower_name', 'LIKE', "%{$search}%")
                  ->orHaving('partner_name', 'LIKE', "%{$search}%")
                  ->orHaving('tier_name', 'LIKE', "%{$search}%")
                  ->orHaving('formatted_request_date', 'LIKE', "%{$search}%");
                
                if (is_numeric($search)) {
                    $q->orHaving('pending_count', '=', (int)$search);
                }
            });
        }

        // Sorting
        $sortField = $filters['sort_field'] ?? 'request_date';
        $sortOrder = strtolower($filters['sort_order'] ?? 'desc') === 'asc' ? 'asc' : 'desc';

        if ($sortField === 'borrower_name') {
            $query->orderBy(
                Borrower::select(DB::raw("CONCAT(first_name, ' ', last_name)"))
                    ->whereColumn('borrowers.id', 'borrower_task_verification_requests.borrower_id')
                    ->limit(1),
                $sortOrder
            );
        } elseif ($sortField === 'partner_name') {
            $query->orderBy(
                Partner::select('full_name')
                    ->whereColumn('partners.id', 'borrower_task_verification_requests.partner_id')
                    ->limit(1),
                $sortOrder
            );
        } elseif ($sortField === 'tier_name') {
            $query->orderBy(
                Tier::select('name')
                    ->whereColumn('tiers.id', 'borrower_task_verification_requests.tier_id')
                    ->limit(1),
                $sortOrder
            );
        } elseif ($sortField === 'pending_count') {
            $query->orderBy('pending_count', $sortOrder);
        } else {
            $query->orderBy('request_date', $sortOrder);
        }

        // Pagination
        $perPage = isset($filters['per_page']) ? max(1, (int) $filters['per_page']) : 10;
        $page = isset($filters['page']) ? max(1, (int) $filters['page']) : 1;

        $paginator = $this->paginateBuilderWith($query, $perPage, $page);

        return $this->respondWithPaginationResource(
            $paginator,
            BorrowerTaskVerificationRequestResource::class,
            __('verification_center.success_retrieved')
        );
    }

    /**
     * Fetch all verification requests for a specific borrower.
     */
    public function fetchBorrowerRequests(array $filters)
    {
        $query = BorrowerTaskVerificationRequest::query()
            ->with(['borrower:id,first_name,last_name', 'tier:id,name', 'task:id,title']);

        if (!empty($filters['borrower_id'])) {
            $query->where('borrower_id', $filters['borrower_id']);
        }

        if (!empty($filters['partner_id'])) {
            $query->where('partner_id', $filters['partner_id']);
        }

        // Apply partner security filter if not already filtered or restricted
        $authUser = auth()->user();
        if ($authUser && $authUser->partner && $authUser->partner->is_host != 1) {
            $query->where('partner_id', $authUser->partner_id);
        }

        // Global search across task title and tier name
        if (!empty($filters['search'])) {
            $search = trim((string) $filters['search']);
            $query->where(function ($q) use ($search) {
                $q->whereHas('task', function ($q) use ($search) {
                    $q->where('title', 'LIKE', "%{$search}%");
                })->orWhereHas('tier', function ($q) use ($search) {
                    $q->where('name', 'LIKE', "%{$search}%");
                })->orWhere('status', 'LIKE', "%{$search}%")
                ->orWhereRaw("DATE_FORMAT(created_at, '%M %d, %Y %h:%i %p') LIKE ?", ["%{$search}%"]);
            });
        }

        // Sorting
        $sortField = $filters['sort_field'] ?? 'created_at';
        if ($sortField === 'request_date') {
            $sortField = 'created_at';
        }
        $sortOrder = strtolower($filters['sort_order'] ?? 'desc') === 'asc' ? 'asc' : 'desc';

        if ($sortField === 'task_name') {
            $query->join('tier_tasks', 'borrower_task_verification_requests.task_id', '=', 'tier_tasks.id')
                ->orderBy('tier_tasks.title', $sortOrder)
                ->select('borrower_task_verification_requests.*');
        } elseif ($sortField === 'tier_name') {
            $query->join('tiers', 'borrower_task_verification_requests.tier_id', '=', 'tiers.id')
                ->orderBy('tiers.name', $sortOrder)
                ->select('borrower_task_verification_requests.*');
        } else {
            $query->orderBy('borrower_task_verification_requests.' . $sortField, $sortOrder);
        }

        $perPage = isset($filters['per_page']) ? max(1, (int) $filters['per_page']) : 10;
        $page = isset($filters['page']) ? max(1, (int) $filters['page']) : 1;

        $paginator = $this->paginateBuilderWith($query, $perPage, $page);

        return $this->respondWithPaginationResource(
            $paginator,
            \App\Http\Resources\FCH\CreditBuilder\BorrowerTaskVerificationRequestDetailResource::class,
            __('verification_center.success_retrieved')
        );
    }
}
