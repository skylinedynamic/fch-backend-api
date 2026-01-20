<?php

namespace App\Services\FCH\Common\Application;

use App\Helpers\ApiResponse;
use App\Models\Borrower\FundingApplication;
use App\Models\Borrower\PreQualificationRequest;
use App\Http\Resources\Borrower\MyApplication\PreQualificationApplicationResource;
use App\Http\Resources\Borrower\MyApplication\FundingApplicationResource;
use App\Services\Global\Datatable\QueryFilter;
use App\Traits\PaginatesApi;
use Illuminate\Database\Eloquent\Builder;

class RequestApplicationService
{
    use PaginatesApi;

    /**
     * Get PreQualification Applications List
     */
    public function getPreQualificationList(array $filters)
    {
        $partnerId = auth()->user()->partner_id;
        
        $query = PreQualificationRequest::query()
        ->where('status', '!=', PreQualificationRequest::ON_HOLD)
        ->whereHas('borrower', function ($q) use($partnerId) {
            $q->where('partner_id', $partnerId);
        });

        if(isset($filters['status']) && !empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if(isset($filters['start_date']) && !empty($filters['start_date'])) {
            $query->where('created_at', '>=', $filters['start_date']);
        }

        if(isset($filters['end_date']) && !empty($filters['end_date'])) {
            $query->where('created_at', '<=', $filters['end_date']);
        }

        if(isset($filters['funding_type']) && !empty($filters['funding_type'])) {
            $query->where('purpose_of_funding', $filters['funding_type']);
        }

        // Apply Search
        if (!empty($filters['search'])) {
            $search = trim((string) $filters['search']);
            $query->search($search);
        }

        // Apply Sorting & Advanced Filters using QueryFilter if available, or manual simple sorting
        $sortField = $filters['sort_field'] ?? 'id';
        $sortOrder = strtolower($filters['sort_order'] ?? 'desc') === 'asc' ? 'asc' : 'desc';
        $advancedFilters = $filters['filters'] ?? [];


        if($sortField == 'funding_type') {
            $sortField = 'purpose_of_funding';
        }elseif($sortField == 'requested_amount') {
            $sortField = 'funding_requested_amount';
        }elseif($sortField == 'date') {
            $sortField = 'created_at';
        }elseif($sortField == 'full_name') {
            $sortField = 'first_name';
        }


        $query = QueryFilter::apply(
            $query,
            $sortField,
            $sortOrder,
            $advancedFilters,
             [

                'aliases' => [
                    'purpose_of_funding' => 'funding_type',
                    'funding_requested_amount' => 'requested_amount',
                    'created_at' => 'date',
                    'first_name' => 'full_name',
                ],
            ]
        );

        // Pagination
        $perPage = isset($filters['per_page']) ? max(1, (int) $filters['per_page']) : 10;
        $page = isset($filters['page']) ? max(1, (int) $filters['page']) : 1;

        $paginator = $this->paginateBuilderWith($query, $perPage, $page);

        return $this->respondWithPaginationResource(
            $paginator, 
            PreQualificationApplicationResource::class,
            __('borrower.success.retrieved') ?? 'Applications retrieved successfully'
        );
    }

    /**
     * Get Funding Applications List
     */
    public function getFundingApplicationList(array $filters)
    {
        $partnerId = auth()->user()->partner_id;
        
        $query = FundingApplication::query()
            ->where('status', '!=', FundingApplication::ON_HOLD)
            ->whereHas('borrower', function ($q) use($partnerId) {
                $q->where('partner_id', $partnerId);
            }); 

        // Apply Search
        if (!empty($filters['search'])) {
            $search = trim((string) $filters['search']);
            $query->search($search);
        }

        $sortField = $filters['sort_field'] ?? 'id';
        $sortOrder = strtolower($filters['sort_order'] ?? 'desc') === 'asc' ? 'asc' : 'desc';
        $advancedFilters = $filters['filters'] ?? [];

        $query = QueryFilter::apply(
            $query,
            $sortField,
            $sortOrder,
            $advancedFilters,
            []
        );

        $perPage = isset($filters['per_page']) ? max(1, (int) $filters['per_page']) : 10;
        $page = isset($filters['page']) ? max(1, (int) $filters['page']) : 1;

        $paginator = $this->paginateBuilderWith($query, $perPage, $page);

        return $this->respondWithPaginationResource(
            $paginator,
            FundingApplicationResource::class,
            __('borrower.success.retrieved') ?? 'Funding applications retrieved successfully'
        );
    }
    /**
     * Get Pre-Qualification Application By ID
     */
    public function getPreQualificationById($id)
    {
        $application = PreQualificationRequest::with('borrower')->findOrFail($id);
        return ApiResponse::success(new \App\Http\Resources\FCH\Common\Application\PreQualificationRequestResource($application));
    }

    /**
     * Get Funding Application By ID
     */
    public function getFundingApplicationById($id)
    {
        $application = FundingApplication::with('borrower')->findOrFail($id);
        return ApiResponse::success(new \App\Http\Resources\FCH\Common\Application\FundingApplicationResource($application));
    }
}
