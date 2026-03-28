<?php

namespace App\Http\Controllers\Api\V1\FCH\Common\ManageAgreement;

use App\Http\Controllers\Controller;
use App\Helpers\ApiResponse;
use App\Services\FCH\Common\ManageAgreement\AgreementService;
use Illuminate\Http\Request;

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
     * Submit / save the signed agreement for the authenticated partner.
     */
    public function submitSign(Request $request)
    {
        $request->validate([
            'agreement_template_id' => 'required|integer|exists:agreement_templates,id',
        ]);

        try {
            $user = $request->user();
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
            // Find target user ID (current user or specifically requested one)
            $targetUserId = $userId ?: $request->user()->id;
            
            return $this->agreementService->downloadAgreement((int)$targetUserId);
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
            $targetUserId = $userId ?: $request->user()->id;
            $html = $this->agreementService->getSignedHtml((int)$targetUserId);
            return ApiResponse::success(['content_html' => $html]);
        } catch (\Exception $e) {
            return ApiResponse::serverError(__('agreement.retrieval_failed') . ': ' . $e->getMessage());
        }
    }
}
