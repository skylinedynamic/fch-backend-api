<?php

namespace App\Services\Borrower\Common;

use App\Helpers\ApiResponse;
use App\Models\Borrower\Borrower;
use App\Models\User\User;
use Illuminate\Support\Facades\Auth;
use App\Http\Resources\Borrower\Common\AdvisorResource;

class BorrowerCommonService
{
    public function getAdvisor()
    {
        $user = Auth::user();

        if (!$user->entity_id || $user->entity_type !== Borrower::class) {
             return ApiResponse::error(__('borrower.borrower_not_found'), 404);
        }

        $borrower = Borrower::with('assignedUser')->find($user->entity_id);

        if (!$borrower) {
            return ApiResponse::error(__('borrower.borrower_not_found'), 404);
        }

        if (!$borrower->assignedUser) {
             return ApiResponse::error(__('borrower.advisor_not_assigned'), 404);
        }

        return ApiResponse::success(new AdvisorResource($borrower->assignedUser), __('borrower.advisor_retrieved'));
    }
}
