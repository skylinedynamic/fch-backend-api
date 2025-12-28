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
                DB::raw("COUNT(CASE WHEN status = '" . BorrowerTaskVerificationRequest::PROCESSING . "' THEN 1 END) as pending_count"),
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

        foreach ($inputFilters as $field => $filterData) {
            $constraints = is_array($filterData) && isset($filterData['constraints']) 
                ? $filterData['constraints'] 
                : [['value' => is_array($filterData) ? ($filterData['value'] ?? null) : $filterData]];

            foreach ($constraints as $constraint) {
                $val = $constraint['value'] ?? null;
                if ($val === null || $val === '') continue;

                if ($field === 'borrower_name') {
                    $query->having('borrower_name', 'LIKE', "%{$val}%");
                } elseif ($field === 'tier_name') {
                    $query->having('tier_name', 'LIKE', "%{$val}%");
                } elseif ($field === 'partner_name') {
                    $query->having('partner_name', 'LIKE', "%{$val}%");
                } elseif ($field === 'pending_count') {
                    $query->having('pending_count', '=', (int)$val);
                } elseif ($field === 'request_date') {
                    $query->having('formatted_request_date', 'LIKE', "%{$val}%");
                }
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
            ->with(['borrower:id,first_name,last_name,status', 'tier:id,name', 'task:id,title']);

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

        // PrimeReact DataTable filters (Nested constraints)
        if (!empty($filters['filters'])) {
            foreach ($filters['filters'] as $field => $filterData) {
                if (is_array($filterData) && isset($filterData['constraints'])) {
                    foreach ($filterData['constraints'] as $constraint) {
                        $value = $constraint['value'] ?? null;
                        if ($value === null || $value === '') continue;

                        $query->where(function ($q) use ($field, $value) {
                            if ($field === 'task_title') {
                                $q->whereHas('task', function ($sq) use ($value) {
                                    $sq->where('title', 'LIKE', "%{$value}%");
                                });
                            } elseif ($field === 'tier_name') {
                                $q->whereHas('tier', function ($sq) use ($value) {
                                    $sq->where('name', 'LIKE', "%{$value}%");
                                });
                            } elseif ($field === 'borrower_name') {
                                $q->whereHas('borrower', function ($sq) use ($value) {
                                    $sq->where(DB::raw("CONCAT(first_name, ' ', last_name)"), 'LIKE', "%{$value}%");
                                });
                            } elseif ($field === 'partner_name') {
                                $q->whereHas('partner', function ($sq) use ($value) {
                                    $sq->where('full_name', 'LIKE', "%{$value}%");
                                });
                            } elseif ($field === 'status') {
                                $q->where('status', 'LIKE', "%{$value}%");
                            } elseif ($field === 'request_date') {
                                $q->whereRaw("DATE_FORMAT(created_at, '%M %d, %Y %h:%i %p') LIKE ?", ["%{$value}%"]);
                            }
                        });
                    }
                }
            }
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
