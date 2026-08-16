<?php

namespace App\Http\Controllers\Api\V1\Borrower\Profile;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Resources\Borrower\Profile\BorrowerCreditProfileResource;
use App\Mail\Borrower\UpdateCreditProfileRequestMail;
use App\Models\Borrower\BorrowerCreditProfile;
use App\Models\Borrower\CreditProfileRequest;
use App\Models\Partner\Partner;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;

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
    public function requestUpdate($id): JsonResponse
    {
        try {
            $user = Auth::user();
            if (!$user || !$user->entity_id) {
                return ApiResponse::error('Unauthorized', 401);
            }

            $creditProfile = BorrowerCreditProfile::where('id', $id)
                ->where('borrower_id', $user->entity_id)
                ->first();

            if (!$creditProfile) {
                return ApiResponse::error('Credit profile not found.', 404);
            }

            // Fetch host partner
            $host = Partner::where('is_host', 1)->first();
            if ($host && $host->email) {
                $creditProfileRequest = CreditProfileRequest::where('borrower_id', $user->entity_id)->first();
                Mail::to($host->email)->send(new UpdateCreditProfileRequestMail($creditProfileRequest));
            }

            return ApiResponse::success(null, 'Request to update iSoftPull data has been sent successfully.');
        } catch (\Exception $e) {
            return ApiResponse::error($e->getMessage(), 500);
        }
    }
}
