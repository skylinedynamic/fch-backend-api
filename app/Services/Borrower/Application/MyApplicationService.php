<?php

namespace App\Services\Borrower\Application;

use App\Http\Resources\Borrower\MyApplication\PreQualificationApplicationResource;
use App\Http\Resources\Borrower\MyApplication\FundingApplicationResource;
use App\Models\Borrower\PreQualificationRequest;
use App\Models\Borrower\FundingApplication;
use App\Traits\PaginatesApi;
use Illuminate\Support\Facades\Auth;

class MyApplicationService
{
    use PaginatesApi;

    public function preQualificationList(array $filters)
    {
        $user = Auth::user();
        $query = PreQualificationRequest::where('status', '!=', PreQualificationRequest::ON_HOLD)
                ->where('borrower_id', $user->entity_id)
                ->latest();

        $perPage = isset($filters['per_page']) ? max(1, (int) $filters['per_page']) : 10;
        $page = isset($filters['page']) ? max(1, (int) $filters['page']) : 1;

        $paginator = $this->paginateBuilderWith($query, $perPage, $page);

        return $this->respondWithPaginationResource(
            $paginator,
            PreQualificationApplicationResource::class,
            __('borrower.get_pre_qualification_applications')
        );
    }

    public function fundingApplicationList(array $filters)
    {
        $user = Auth::user();
        $query = FundingApplication::where('status', '!=', FundingApplication::ON_HOLD)
                ->where('borrower_id', $user->entity_id)
                ->latest();

        $perPage = isset($filters['per_page']) ? max(1, (int) $filters['per_page']) : 10;
        $page = isset($filters['page']) ? max(1, (int) $filters['page']) : 1;

        $paginator = $this->paginateBuilderWith($query, $perPage, $page);

        return $this->respondWithPaginationResource(
            $paginator,
            FundingApplicationResource::class,
            __('borrower.get_funding_applications')
        );
    }

    public function coBorrowerValidation(array $data)
    {
        return \App\Helpers\ApiResponse::success([], __('borrower_validation.validation_success'));
    }
}
