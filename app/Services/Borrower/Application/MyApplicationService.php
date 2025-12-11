<?php

namespace App\Services\Borrower\Application;

use App\Http\Resources\Borrower\MyApplication\MyApplicationResource;
use App\Models\Borrower\PreQualificationRequest;
use App\Traits\PaginatesApi;
use Illuminate\Support\Facades\Auth;

class MyApplicationService
{
    use PaginatesApi;

    public function list(array $filters)
    {
        $user = Auth::user();
        $query = PreQualificationRequest::where('status', '!=', PreQualificationRequest::ON_HOLD)
                ->where('borrower_id', $user->entity_id)
                ->latest();

        $perPage = isset($filters['per_page']) ? max(1, (int) $filters['per_page']) : 15;
        $page = isset($filters['page']) ? max(1, (int) $filters['page']) : 1;

        $paginator = $this->paginateBuilderWith($query, $perPage, $page);

        return $this->respondWithPaginationResource(
            $paginator,
            MyApplicationResource::class,
            __('borrower.get_myapplication')
        );
    }
}
