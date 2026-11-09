<?php

namespace App\Http\Controllers\Api\V1\FCH\Common\ManageAgreement;

use App\Exceptions\AgreementFieldsInvalidException;
use App\Exceptions\AgreementVersionOutdatedException;
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
     * Excuse one partner or client from signing their agreement, or require it
     * again. The host's team only - never the partner or client themselves.
     */
    /** Host only: whether this partner / client is required to sign, and whether that can be changed now. */
    public function requirement(int $userId)
    {
        if (!$this->isHostTeam()) {
            return ApiResponse::notFound(__('agreement.not_found'));
        }

        $status = $this->agreementService->requirementStatus($userId);

        return $status === null
            ? ApiResponse::notFound(__('agreement.signer_not_found'))
            : ApiResponse::success($status);
    }

    public function setRequired(Request $request, int $userId)
    {
        $request->validate(['required' => 'required|boolean']);

        if (!$this->isHostTeam()) {
            return ApiResponse::notFound(__('agreement.not_found'));
        }

        // Locked while signing, or the agreement they would sign, is off in Settings.
        $status = $this->agreementService->requirementStatus($userId);
        if ($status === null) {
            return ApiResponse::notFound(__('agreement.signer_not_found'));
        }
        if (!$status['can_change']) {
            return ApiResponse::error($status['locked_reason'], 422);
        }

        try {
            $result = $this->agreementService->setRequired($userId, $request->boolean('required'));
        } catch (\Exception $e) {
            return ApiResponse::serverError(__('agreement.required_update_failed') . ': ' . $e->getMessage());
        }

        if ($result === null) {
            return ApiResponse::notFound(__('agreement.signer_not_found'));
        }

        return ApiResponse::success($result, __('agreement.required_updated_successfully'));
    }

    /**
     * Submit / save the signed agreement for the authenticated partner.
     */
    public function submitSign(Request $request)
    {
        $request->validate([
            'agreement_template_id' => 'required|integer|exists:agreement_templates,id',
            'agreement_template_version_id' => 'nullable|integer',
            'fields' => 'nullable|array',
        ]);

        try {
            $user = Auth::user();
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
            if (!$targetUserId = $this->validateAccess($userId)) {
                return ApiResponse::notFound(__('agreement.not_found'));
            }
            return $this->agreementService->downloadAgreement($targetUserId);
        } catch (\Exception $e) {
            return ApiResponse::serverError(__('agreement.download_failed') . ': ' . $e->getMessage());
        }
    }

    /**
     * Get the raw signed HTML content for frontend preview, and whether the
     * record is marked as signed - signed with no content means the signed
     * copy is missing (the page then offers Re-sign).
     */
    public function getSignedHtml(Request $request, $userId = null)
    {
        try {
            if (!$targetUserId = $this->validateAccess($userId)) {
                return ApiResponse::notFound(__('agreement.not_found'));
            }
            return ApiResponse::success([
                'content_html' => $this->agreementService->getSignedHtml($targetUserId),
                'has_sign' => $this->agreementService->isMarkedSigned($targetUserId),
            ]);
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

        // Only the host's team reaches someone else's agreement; everyone else
        // - non-host partners and their staff, and borrowers - only their own.
        return $this->isHostTeam() ? $targetUserId : null;
    }

    /** The host's admins and staff - not a non-host partner's team, and never a borrower. */
    private function isHostTeam(): bool
    {
        $user = auth()->user();

        return (bool) optional($user->partner)->is_host && $user->entity_type !== Borrower::class;
    }
}
