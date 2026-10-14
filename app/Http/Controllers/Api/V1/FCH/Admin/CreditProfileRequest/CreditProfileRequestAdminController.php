<?php

namespace App\Http\Controllers\Api\V1\FCH\Admin\CreditProfileRequest;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Resources\FCH\CreditProfileRequest\CreditProfileRequestResource;
use App\Models\Borrower\CreditProfileRequest;
use App\Models\CreditAuthorization\CreditAuthorizationConsent;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class CreditProfileRequestAdminController extends Controller
{
    /**
     * List credit profile requests with filters and pagination.
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $perPage = $request->input('per_page', 15);
            $search = $request->input('search');

            $query = CreditProfileRequest::with('borrower')->orderBy('created_at', 'desc');

            if (!empty($search)) {
                $query->where(function ($q) use ($search) {
                    $q->where('first_name', 'like', "%{$search}%")
                      ->orWhere('last_name', 'like', "%{$search}%")
                      ->orWhere('email', 'like', "%{$search}%")
                      ->orWhere('phone', 'like', "%{$search}%")
                      ->orWhereHas('borrower', function ($qb) use ($search) {
                          $qb->where('first_name', 'like', "%{$search}%")
                             ->orWhere('last_name', 'like', "%{$search}%")
                             ->orWhere('business_name', 'like', "%{$search}%");
                      });
                });
            }

            $requests = $query->paginate($perPage);

            // Whether each client is still waiting for their pull, so the list
            // shows it without opening every row. Status only - the consent
            // records behind it stay host-only on the details screen.
            $statuses = CreditAuthorizationConsent::requestStatusesForBorrowers(
                $requests->getCollection()->pluck('borrower_id')->all()
            );
            $requests->getCollection()->each(
                fn ($row) => $row->setAttribute('request_status', $statuses[$row->borrower_id] ?? null)
            );

            return ApiResponse::success($requests, __('credit_profile_request.retrieved_successfully'));
        } catch (\Exception $e) {
            Log::error(__('credit_profile_request.log_retrieval_failed', ['error' => $e->getMessage()]));
            return ApiResponse::serverError(__('credit_profile_request.retrieval_failed'));
        }
    }

    /**
     * Show details of a specific credit profile request.
     */
    public function show(int $id): JsonResponse
    {
        try {
            $creditProfileRequest = CreditProfileRequest::with('borrower')->find($id);

            if (!$creditProfileRequest) {
                return ApiResponse::notFound(__('credit_profile_request.not_found'));
            }

            // The FCRA authorisations behind this client's pulls travel with the
            // details rather than on an endpoint of their own - the screen shows
            // them together, so fetching them together keeps it to one call.
            //
            // Host partner only: these rows carry the consumer's IP and device
            // and exist to answer an audit of our own permissible purpose. A
            // non-host simply gets an empty list, not an error.
            $creditProfileRequest->setAttribute(
                'credit_authorizations',
                $this->isHostUser()
                    ? CreditAuthorizationConsent::forBorrower($creditProfileRequest->borrower_id)
                    : []
            );
            $creditProfileRequest->setAttribute(
                'credit_last_pulled_at',
                $this->isHostUser()
                    ? CreditAuthorizationConsent::lastPulledForBorrower($creditProfileRequest->borrower_id)
                    : null
            );

            return ApiResponse::success(new CreditProfileRequestResource($creditProfileRequest), __('credit_profile_request.details_retrieved_successfully'));
        } catch (\Exception $e) {
            Log::error(__('credit_profile_request.log_details_retrieval_failed', ['error' => $e->getMessage()]));
            return ApiResponse::serverError(__('credit_profile_request.details_retrieval_failed'));
        }
    }

    /**
     * Only the host partner sees authorisation records. Checked here rather than
     * left to a permission name so that adding a field to this response cannot
     * quietly widen who sees a consumer's IP and device.
     */
    private function isHostUser(): bool
    {
        return (bool) (Auth::user()?->partner?->is_host);
    }
}
