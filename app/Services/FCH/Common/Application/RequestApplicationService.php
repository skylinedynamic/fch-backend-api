<?php

namespace App\Services\FCH\Common\Application;

use App\Helpers\ApiResponse;
use App\Models\Borrower\FundingApplication;
use App\Models\Borrower\PreQualificationRequest;
use App\Http\Resources\Borrower\MyApplication\PreQualificationApplicationResource;
use App\Http\Resources\Borrower\MyApplication\FundingApplicationResource;
use App\Http\Resources\FCH\Common\Application\FundingApplicationResource as ApplicationFundingApplicationResource;
use App\Http\Resources\FCH\Common\Application\PreQualificationRequestResource;
use App\Services\Global\Datatable\QueryFilter;
use App\Traits\PaginatesApi;
use Illuminate\Database\Eloquent\Builder;

class RequestApplicationService
{
    use PaginatesApi;

    /**
     * Get the authenticated user's partner ID
     *
     * @return int
     */
    private function getAuthenticatedPartnerId(): int
    {
        return auth()->user()->partner_id;
    }

    /**
     * Get PreQualification Applications List
     */
    public function getPreQualificationList(array $filters)
    {
        $partnerId = $this->getAuthenticatedPartnerId();
        
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
            __('application.pre_qualification_retrieved')
        );
    }

    /**
     * Get Funding Applications List
     */
    public function getFundingApplicationList(array $filters)
    {
        $partnerId = $this->getAuthenticatedPartnerId();
        
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
            __('application.funding_application_retrieved')
        );
    }
    /**
     * Get Pre-Qualification Application By ID
     */
    public function getPreQualificationById($id)
    {
        $partnerId = $this->getAuthenticatedPartnerId();
        
        $application = PreQualificationRequest::with('borrower')
           ->whereHas('borrower', function ($q) use($partnerId) {
            $q->where('partner_id', $partnerId);
        })
        ->where('id',$id)->first();

        if($application == null){
            return ApiResponse::error(__('application.pre_qualification_not_found'));
        }
        return ApiResponse::success(new PreQualificationRequestResource($application));
    }

    /**
     * Get Funding Application By ID
     */
    public function getFundingApplicationById($id)
    {
        $partnerId = $this->getAuthenticatedPartnerId();

        $application = FundingApplication::with('borrower')
           ->whereHas('borrower', function ($q) use($partnerId) {
            $q->where('partner_id', $partnerId);
        })
        ->where('id',$id)->first();

        if($application == null){
            return ApiResponse::error(__('application.funding_application_not_found'));
        }
        return ApiResponse::success(new ApplicationFundingApplicationResource($application));
    }

    /**
     * Update Pre-qualification Application Status
     */
    public function preQualificationStatusUpdate(array $data)
    {
        $partnerId = $this->getAuthenticatedPartnerId();

        $application = PreQualificationRequest::with('borrower')
           ->whereHas('borrower', function ($q) use($partnerId) {
            $q->where('partner_id', $partnerId);
        })
        ->where('id',$data['id'])->first();

        if($application == null){
            return ApiResponse::error(__('application.pre_qualification_not_found'));
        }

        $application->status = $data['status'];
        if (isset($data['status_note'])) {
            $application->status_note = $data['status_note'];
        }
        $application->save();

        return ApiResponse::success(new PreQualificationRequestResource($application), __('application.status_updated'));
    }

    /**
     * Update Funding Application Status
     */
    public function fundingApplicationStatusUpdate(array $data)
    {
        $partnerId = $this->getAuthenticatedPartnerId();

        $application = FundingApplication::with('borrower')
           ->whereHas('borrower', function ($q) use($partnerId) {
            $q->where('partner_id', $partnerId);
        })
        ->where('id',$data['id'])->first();

        if($application == null){
            return ApiResponse::error(__('application.funding_application_not_found'));
        }

        $application->status = $data['status'];
        if (isset($data['status_note'])) {
            $application->status_note = $data['status_note'];
        }
        $application->save();

        return ApiResponse::success(new ApplicationFundingApplicationResource($application), __('application.status_updated'));
    }
    /**
     * Get Pre-Qualification Card Summary
     */
    public function getPreQualificationCardSummary()
    {
        $partnerId = $this->getAuthenticatedPartnerId();

        $baseQuery = PreQualificationRequest::where('status', '!=', PreQualificationRequest::ON_HOLD)
            ->whereHas('borrower', function ($q) use($partnerId) {
                $q->where('partner_id', $partnerId);
            });

        return ApiResponse::success([
            'total_applications' => (clone $baseQuery)->count(),
            'approved' => (clone $baseQuery)->where('status', PreQualificationRequest::APPROVED)->count(),
            'pending' => (clone $baseQuery)->where('status', PreQualificationRequest::PENDING)->count(),
            'approved_amount' => (clone $baseQuery)->where('status', PreQualificationRequest::APPROVED)->sum('funding_requested_amount'),
        ]);
    }

    /**
     * Get Funding Application Card Summary
     */
    public function getFundingApplicationCardSummary()
    {
        $partnerId = $this->getAuthenticatedPartnerId();

        $baseQuery = FundingApplication::where('status', '!=', FundingApplication::ON_HOLD)
            ->whereHas('borrower', function ($q) use($partnerId) {
                $q->where('partner_id', $partnerId);
            });

        return ApiResponse::success([
            'total_applications' => (clone $baseQuery)->count(),
            'approved' => (clone $baseQuery)->where('status', FundingApplication::APPROVED)->count(),
            'pending' => (clone $baseQuery)->where('status', FundingApplication::PENDING)->count(),
            'approved_amount' => (clone $baseQuery)->where('status', FundingApplication::APPROVED)->sum('requested_amount'),
        ]);
    }
}
