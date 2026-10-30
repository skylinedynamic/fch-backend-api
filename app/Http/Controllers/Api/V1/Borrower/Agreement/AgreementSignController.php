<?php

namespace App\Http\Controllers\Api\V1\Borrower\Agreement;

use App\Exceptions\AgreementFieldsInvalidException;
use App\Exceptions\AgreementVersionOutdatedException;
use App\Http\Controllers\Controller;
use App\Helpers\ApiResponse;
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
     * Check whether the authenticated borrower must sign an agreement.
     */
    public function checkAgreementSign(Request $request)
    {
        try {
            $user = Auth::user();
            $res = $this->agreementService->checkSignNeed($user);
            return ApiResponse::success($res);
        } catch (\Exception $e) {
            return ApiResponse::serverError(__('agreement.retrieval_failed') . ': ' . $e->getMessage());
        }
    }

    /**
     * Submit / save the signed agreement for the authenticated borrower.
     */
    public function submitSign(Request $request)
    {
        $request->validate([
            'agreement_template_id' => 'required|integer|exists:agreement_templates,id',
            'agreement_template_version_id' => 'nullable|integer',
            'fields' => 'nullable|array',
        ]);

        try {
            $user = $request->user();
            $this->agreementService->submitSign($user, $request->all(), $request->ip(), $request->userAgent());
            return ApiResponse::success(null, __('agreement.signed_successfully'));
        } catch (AgreementVersionOutdatedException $e) {
            return ApiResponse::error($e->getMessage(), 409);
        } catch (AgreementFieldsInvalidException $e) {
            return ApiResponse::validationError(['fields' => $e->fields], $e->getMessage());
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
            $targetUserId = (int) ($userId ?: $request->user()->id);
            if ($targetUserId !== (int) $request->user()->id) {
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
            $targetUserId = (int) ($userId ?: $request->user()->id);
            if ($targetUserId !== (int) $request->user()->id) {
                return ApiResponse::notFound(__('agreement.not_found'));
            }
            $html = $this->agreementService->getSignedHtml($targetUserId);
            return ApiResponse::success(['content_html' => $html]);
        } catch (\Exception $e) {
            return ApiResponse::serverError(__('agreement.retrieval_failed') . ': ' . $e->getMessage());
        }
    }
}
