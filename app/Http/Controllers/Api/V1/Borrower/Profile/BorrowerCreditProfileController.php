<?php

namespace App\Http\Controllers\Api\V1\Borrower\Profile;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Borrower\Profile\RequestCreditProfileUpdateRequest;
use App\Http\Resources\Borrower\Profile\BorrowerCreditProfileResource;
use App\Services\Borrower\Profile\CreditAuthorizationConsentService;
use App\Mail\Borrower\UpdateCreditProfileRequestMail;
use App\Models\Borrower\BorrowerCreditProfile;
use App\Models\Borrower\CreditProfileRequest;
use App\Models\Partner\Partner;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use App\Services\FCH\Common\Notification\Notifier;

class BorrowerCreditProfileController extends Controller
{
    public function show(): JsonResponse
    {
        try {
            $user = Auth::user();
            if (!$user || !$user->entity_id) {
                return ApiResponse::error(__('auth.unauthorized'), 401);
            }

            $borrower = $user->borrower;
            if (!$borrower) {
                return ApiResponse::error(__('credit_profile_request.borrower_not_found'), 404);
            }

            $creditProfile = $borrower->creditProfile;

            if (!$creditProfile) {
                $creditProfileRequest = $borrower->creditProfileRequests;
                return ApiResponse::success($creditProfileRequest, __('credit_profile_request.no_credit_profile_found'));
            }

            return ApiResponse::success(
                new BorrowerCreditProfileResource($creditProfile),
                __('credit_profile_request.credit_profile_fetched')
            );
        } catch (\Exception $e) {
            return ApiResponse::error($e->getMessage(), 500);
        }
    }
    public function requestUpdate(RequestCreditProfileUpdateRequest $request, $id): JsonResponse
    {
        try {
            $user = Auth::user();
            if (!$user || !$user->entity_id) {
                return ApiResponse::error(__('auth.unauthorized'), 401);
            }

            $creditProfile = BorrowerCreditProfile::where('id', $id)
                ->where('borrower_id', $user->entity_id)
                ->first();

            if (!$creditProfile) {
                return ApiResponse::error(__('credit_profile_request.credit_profile_not_found'), 404);
            }

            // An update is a new pull, so it gets its own evidence row. No
            // credit_profile_requests row is created here, which is why the
            // consent records that link as nullable.
            app(CreditAuthorizationConsentService::class)->record(
                (int) $user->entity_id,
                $request
            );

            // Fetch host partner and send them the update request email.
            // Catch \Throwable (not \Exception - a \TypeError from a misconfigured
            // mailer extends \Error, which \Exception does not catch) so a failed
            // send cannot fail this whole request and mask that the update request
            // itself succeeded. Previously this had no inner try/catch at all, so
            // a mail failure surfaced as a 500 on the request-update action.
            $host = Partner::where('is_host', 1)->first();
            if ($host) {
                try {
                    $creditProfileRequest = CreditProfileRequest::where('borrower_id', $user->entity_id)->first();
                    $borrower = $creditProfileRequest?->borrower;

                    app(Notifier::class)->send('host.credit_profile.update_requested', $host, [
                        'borrower' => personName($borrower?->first_name, $borrower?->middle_name, $borrower?->last_name),
                    ], mail: $host->email ? (new UpdateCreditProfileRequestMail($creditProfileRequest))->to($host->email) : null);
                } catch (\Throwable $mailException) {
                    Log::error(__('credit_profile_request.log_update_email_failed', ['error' => $mailException->getMessage()]));
                }
            }

            return ApiResponse::success(null, __('credit_profile_request.update_request_sent'));
        } catch (\Exception $e) {
            return ApiResponse::error($e->getMessage(), 500);
        }
    }
}
