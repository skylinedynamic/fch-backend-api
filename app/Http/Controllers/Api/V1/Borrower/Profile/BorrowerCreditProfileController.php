<?php

namespace App\Http\Controllers\Api\V1\Borrower\Profile;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Resources\Borrower\Profile\BorrowerCreditProfileResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class BorrowerCreditProfileController extends Controller
{
    public function show(): JsonResponse
    {
        try {
            $user = Auth::user();
            if (!$user || !$user->entity_id) {
                return ApiResponse::error('Unauthorized', 401);
            }

            $borrower = $user->borrower;
            if (!$borrower) {
                return ApiResponse::error('Borrower not found', 404);
            }

            $creditProfile = $borrower->creditProfile;
          
            if (!$creditProfile) {
                $creditProfileRequest = $borrower->creditProfileRequests;
                return ApiResponse::success($creditProfileRequest, 'No credit profile found');
            }

            return ApiResponse::success(
                new BorrowerCreditProfileResource($creditProfile),
                'Credit profile fetched successfully.'
            );
        } catch (\Exception $e) {
            return ApiResponse::error($e->getMessage(), 500);
        }
    }
}
