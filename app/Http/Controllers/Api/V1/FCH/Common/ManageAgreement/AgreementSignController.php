<?php

namespace App\Http\Controllers\Api\V1\FCH\Common\ManageAgreement;

use App\Http\Controllers\Controller;
use App\Helpers\ApiResponse;
use App\Models\Borrower\Borrower;
use App\Models\Partner\Partner;
use App\Services\FCH\Common\ManageAgreement\AgreementService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AgreementSignController extends Controller
{
    protected $agreementService;

    public function __construct(AgreementService $agreementService)
    {
        $this->agreementService = $agreementService;
    }

    /**
     * Check whether the authenticated partner must sign an agreement.
     */
    public function checkAgreementSign(Request $request)
    {
        try {
            $user = $request->user();
            $res = $this->agreementService->checkSignNeed($user);
            return ApiResponse::success($res);
        } catch (\Exception $e) {
            return ApiResponse::serverError(__('agreement.retrieval_failed') . ': ' . $e->getMessage());
        }
    }

    /**
     * Cancel / Revoke a signed agreement for the specified user.
     */
    public function cancelSign(Request $request, $userId = null)
    {
        try {
            if (!$targetUserId = $this->validateAccess($userId)) {
                return ApiResponse::notFound(__('agreement.not_found'));
            }
            $this->agreementService->cancelSign($targetUserId);
            return ApiResponse::success(null, __('agreement.cancelled_successfully'));
        } catch (\Exception $e) {
            return ApiResponse::serverError(__('agreement.cancel_failed') . ': ' . $e->getMessage());
        }
    }

    /**
     * Submit / save the signed agreement for the authenticated partner.
     */
    public function submitSign(Request $request)
    {
        $request->validate([
            'agreement_template_id' => 'required|integer|exists:agreement_templates,id',
        ]);

        try {
            $user = Auth::user();
            $this->agreementService->submitSign($user, $request->all(), $request->ip());
            return ApiResponse::success(null, __('agreement.signed_successfully'));
        } catch (\Exception $e) {
            return ApiResponse::serverError(__('agreement.sign_failed') . ': ' . $e->getMessage());
        }
    }

    /**
     * Download the signed agreement as PDF.
     */
    public function downloadAgreement(Request $request, $userId = null)
    {
        try {
            if (!$targetUserId = $this->validateAccess($userId)) {
                return ApiResponse::notFound(__('agreement.not_found'));
            }
            return $this->agreementService->downloadAgreement($targetUserId);
        } catch (\Exception $e) {
            return ApiResponse::serverError(__('agreement.download_failed') . ': ' . $e->getMessage());
        }
    }

    /**
     * Get the raw signed HTML content for frontend preview.
     */
    public function getSignedHtml(Request $request, $userId = null)
    {
        try {
            if (!$targetUserId = $this->validateAccess($userId)) {
                return ApiResponse::notFound(__('agreement.not_found'));
            }
            $html = $this->agreementService->getSignedHtml($targetUserId);
            return ApiResponse::success(['content_html' => $html]);
        } catch (\Exception $e) {
            return ApiResponse::serverError(__('agreement.retrieval_failed') . ': ' . $e->getMessage());
        }
    }

    /**
     * Validate whether the current user can access the target agreement.
     */
    private function validateAccess($userId): ?int
    {
        $user = auth()->user();
        $targetUserId = (int) ($userId ?: $user->id);

        if ($targetUserId === (int) $user->id) return $targetUserId;

        // Non-host partners and borrowers can only see their own
        $isRestricted = ($user->entity_type === Partner::class && !optional($user->partner)->is_host) 
                     || ($user->entity_type === Borrower::class);

        return $isRestricted ? null : $targetUserId;
    }
}
