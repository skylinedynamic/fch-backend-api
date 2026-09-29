<?php

namespace App\Http\Controllers\Api\V1\Borrower\Profile;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Borrower\Profile\StoreCreditProfileRequestRequest;
use App\Http\Resources\Borrower\Profile\CreditProfileRequestResource;
use App\Mail\Borrower\CreditProfileRequestMail;
use App\Models\Partner\Partner;
use App\Services\Borrower\Profile\CreditAuthorizationConsentService;
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
                return ApiResponse::error(__('auth.unauthorized'), 401);
            }

            $creditProfileRequest = $this->service->store($user, $request->creditProfileAttributes());

            // The evidence that this pull was asked for. Written from the
            // client's own request so the IP and user agent are theirs, and
            // straight after the row it authorises so the two can be tied
            // together. The request was already refused above if either box was
            // unticked, so reaching here means a complete consent.
            app(CreditAuthorizationConsentService::class)->record(
                (int) $user->entity_id,
                $request,
                $creditProfileRequest->id
            );

            // Fetch the host partner (is_host = 1) and send them the upload request email.
            // Catch \Throwable, not \Exception - a bad/misconfigured mailer setting
            // surfaces as a \TypeError (extends \Error, not \Exception), which
            // \Exception does not catch. A failed/queued-and-later-failed send must
            // never fail the borrower's request submission itself.
            $host = Partner::where('is_host', 1)->first();
            if ($host && $host->email) {
                try {
                    Mail::to($host->email)->send(
                        // Sent with the host's own mail credentials rather than
                        // left to resolve them: this notification is the host's,
                        // and naming the partner means it does not depend on the
                        // unconfigured-tenant fallback happening to land there.
                        new CreditProfileRequestMail($creditProfileRequest, (int) $host->id)
                    );
                } catch (\Throwable $mailException) {
                    Log::error(__('credit_profile_request.log_upload_email_failed', ['error' => $mailException->getMessage()]));
                }
            }

            return ApiResponse::success(
                new CreditProfileRequestResource($creditProfileRequest),
                __('credit_profile_request.submitted_successfully')
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
                return ApiResponse::error(__('auth.unauthorized'), 401);
            }

            $creditProfileRequest = $this->service->getLatestRequest($user->entity_id);

            return ApiResponse::success(
                new CreditProfileRequestResource($creditProfileRequest),
                __('credit_profile_request.fetched_successfully')
            );
        } catch (\Exception $e) {
            return ApiResponse::error($e->getMessage(), 500);
        }
    }
}

