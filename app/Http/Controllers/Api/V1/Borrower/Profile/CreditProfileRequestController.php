<?php

namespace App\Http\Controllers\Api\V1\Borrower\Profile;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Borrower\Profile\StoreCreditProfileRequestRequest;
use App\Http\Resources\Borrower\Profile\CreditProfileRequestResource;
use App\Mail\Borrower\CreditProfileRequestMail;
use App\Models\Partner\Partner;
use App\Services\Borrower\Profile\CreditProfileRequestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class CreditProfileRequestController extends Controller
{
    protected CreditProfileRequestService $service;

    public function __construct(CreditProfileRequestService $service)
    {
        $this->service = $service;
    }

    /**
     * Store a new credit profile request and mark the borrower's credit profile as updated.
     */
    public function store(StoreCreditProfileRequestRequest $request): JsonResponse
    {
        try {
            $user = Auth::user();
            if (!$user || !$user->entity_id) {
                return ApiResponse::error('Unauthorized', 401);
            }

            $creditProfileRequest = $this->service->store($user, $request->validated());

            // Fetch the host partner (is_host = 1) and send them the upload request email
            $host = Partner::where('is_host', 1)->first();
            if ($host && $host->email) {
                try {
                    Mail::to($host->email)->send(new CreditProfileRequestMail($creditProfileRequest));
                } catch (\Exception $mailException) {
                    Log::error("Failed to send credit profile upload email to host: " . $mailException->getMessage());
                }
            }

            return ApiResponse::success(
                new CreditProfileRequestResource($creditProfileRequest),
                'Credit profile request submitted successfully.'
            );
        } catch (\Exception $e) {
            return ApiResponse::error($e->getMessage(), 500);
        }
    }

    /**
     * Get the submitted credit profile request.
     */
    public function show(): JsonResponse
    {
        try {
            $user = Auth::user();
            if (!$user || !$user->entity_id) {
                return ApiResponse::error('Unauthorized', 401);
            }

            $creditProfileRequest = $this->service->getLatestRequest($user->entity_id);

            return ApiResponse::success(
                new CreditProfileRequestResource($creditProfileRequest),
                'Credit profile request fetched successfully.'
            );
        } catch (\Exception $e) {
            return ApiResponse::error($e->getMessage(), 500);
        }
    }
}

