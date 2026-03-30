<?php

namespace App\Http\Controllers\Api\V1\FCH\Common\ManageAgreement;

use App\Http\Controllers\Controller;
use App\Helpers\ApiResponse;
use App\Http\Requests\Api\V1\FCH\Common\ManageAgreement\AgreementTemplateStoreRequest;
use App\Http\Requests\Api\V1\FCH\Common\ManageAgreement\AgreementTemplateShowRequest;
use App\Http\Resources\FCH\Common\ManageAgreement\AgreementTemplateResource;
use App\Services\FCH\Common\ManageAgreement\AgreementService;
use Illuminate\Http\Request;

class AgreementTemplateController extends Controller
{
    protected $agreementService;

    public function __construct(AgreementService $agreementService)
    {
        $this->agreementService = $agreementService;
    }

    /**
     * Get All Agreement Templates (List)
     */
    public function index(Request $request)
    {
        try {
            $user = $request->user();
            $agreements = $this->agreementService->getTemplates($user->partner_id);

            return ApiResponse::success(
                AgreementTemplateResource::collection($agreements),
                __('agreement.retrieved_successfully')
            );
        } catch (\Exception $e) {
            return ApiResponse::serverError(__('agreement.retrieval_failed') . ': ' . $e->getMessage());
        }
    }

    /**
     * Store/Update Agreement Template
     */
    public function store(AgreementTemplateStoreRequest $request, $id = null)
    {
        try {
            $partnerId = auth()->user()->partner_id;
            $agreement = $this->agreementService->storeTemplate($request->all(), $partnerId, $id);

            return ApiResponse::success(
                new AgreementTemplateResource($agreement),
                __('agreement.saved_successfully')
            );
        } catch (\Exception $e) {
            return ApiResponse::serverError(__('agreement.save_failed') . ': ' . $e->getMessage());
        }
    }

    /**
     * Get Agreement Template by partner_id and slug (POST)
     */
    public function show(AgreementTemplateShowRequest $request)
    {
        try {
            $partnerId = $request->user()->partner_id;
            $agreement = $this->agreementService->showTemplate($partnerId, $request->slug);

            return ApiResponse::success(
                new AgreementTemplateResource($agreement),
                __('agreement.retrieved_successfully')
            );
        } catch (\Exception $e) {
            return ApiResponse::serverError(__('agreement.retrieval_failed') . ': ' . $e->getMessage());
        }
    }

    /**
     * Update Agreement Status (Active/Inactive)
     */
    public function updateStatus(Request $request, $id)
    {
        try {
            $partnerId = $request->user()->partner_id;
            $agreement = $this->agreementService->updateTemplateStatus($id, $partnerId, $request->is_active);

            return ApiResponse::success(
                new AgreementTemplateResource($agreement),
                __('agreement.status_updated_successfully')
            );
        } catch (\Exception $e) {
            return ApiResponse::serverError(__('agreement.status_update_failed') . ': ' . $e->getMessage());
        }
    }
}
