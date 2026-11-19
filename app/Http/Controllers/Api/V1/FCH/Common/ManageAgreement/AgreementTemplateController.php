<?php

namespace App\Http\Controllers\Api\V1\FCH\Common\ManageAgreement;

use App\Http\Controllers\Controller;
use App\Helpers\ApiResponse;
use App\Http\Requests\Api\V1\FCH\Common\ManageAgreement\AgreementTemplateStoreRequest;
use App\Http\Requests\Api\V1\FCH\Common\ManageAgreement\AgreementTemplateShowRequest;
use App\Http\Requests\Api\V1\FCH\Common\ManageAgreement\AgreementMergeFieldsRequest;
use App\Http\Requests\Api\V1\FCH\Common\ManageAgreement\AgreementPreviewSignersRequest;
use App\Http\Requests\Api\V1\FCH\Common\ManageAgreement\AgreementPreviewValuesRequest;
use App\Services\FCH\Common\ManageAgreement\AgreementCommissionFields;
use App\Services\FCH\Common\ManageAgreement\AgreementEnrollmentFields;
use App\Services\FCH\Common\ManageAgreement\AgreementPlanFields;
use App\Http\Resources\FCH\Common\ManageAgreement\AgreementTemplateResource;
use App\Services\FCH\Common\ManageAgreement\AgreementService;
use App\Services\FCH\Partner\PartnerConfigurationChangeRecorder;
use App\Models\Partner\Partner;
use App\Services\FCH\Common\ManageAgreement\AgreementMergeFields;
use App\Services\FCH\Common\ManageAgreement\AgreementPreviewSigners;
use App\Traits\ResolvesAgreementOwner;
use Illuminate\Http\Request;

/**
 * A partner's agreement templates. The signed-in user's own partner, or - from
 * Partner profile › Configuration - another partner named with `partner_id`.
 */
class AgreementTemplateController extends Controller
{
    use ResolvesAgreementOwner;

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
        $partnerId = $this->agreementOwnerId($request);

        try {
            $agreements = $this->agreementService->getTemplates($partnerId);

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
        $partnerId = $this->agreementOwnerId($request);

        try {
            $agreement = $this->agreementService->storeTemplate($request->except('partner_id'), $partnerId, $id);
        } catch (\Exception $e) {
            return ApiResponse::serverError(__('agreement.save_failed') . ': ' . $e->getMessage());
        }

        $this->recordConfigChange(
            $request,
            PartnerConfigurationChangeRecorder::AREA_AGREEMENT,
            "updated the {$agreement->slug} agreement"
        );

        return ApiResponse::success(
            new AgreementTemplateResource($agreement),
            __('agreement.saved_successfully')
        );
    }

    /**
     * Get Agreement Template by partner_id and slug (POST)
     */
    public function show(AgreementTemplateShowRequest $request)
    {
        $partnerId = $this->agreementOwnerId($request);

        try {
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
     * The template owner's company details, for the builder's preview of the
     * {{owner.*}} merge fields - the values signing fills them with.
     */
    public function previewValues(AgreementPreviewValuesRequest $request)
    {
        $partner = Partner::find($this->agreementOwnerId($request));
        $mergeFields = new AgreementMergeFields();

        if (!$request->filled('signer_user_id')) {
            return ApiResponse::success($mergeFields->ownerValues($partner), __('agreement.retrieved_successfully'));
        }

        // Preview as Partner / Borrower: a partner (partner agreement) or client
        // (borrower agreement) the viewer may list (AgreementPreviewSigners).
        $type = $request->validated('type');
        $signers = new AgreementPreviewSigners();
        $signer = $signers->find($request->user(), $type, (int) $request->validated('signer_user_id'));
        if (!$signer) {
            return ApiResponse::notFound(__('agreement.signer_not_found'));
        }

        // The owner fields as this signer sees them: a client's own partner, the host for a partner.
        return ApiResponse::success($mergeFields->values($signer, $signers->ownerFor($signer, $type), $type), __('agreement.retrieved_successfully'));
    }

    /**
     * Agreement Builder › Preview as Partner / Borrower: the partners (`type=partner`)
     * or clients (`type=borrower`) the viewer may preview an agreement as
     * (AgreementPreviewSigners) - every one for the host's team, a partner's own clients otherwise.
     */
    public function previewSigners(AgreementPreviewSignersRequest $request)
    {
        // Naming another partner still needs the host's permission, though the list is the viewer's.
        $this->agreementOwnerId($request);

        return ApiResponse::success(
            (new AgreementPreviewSigners())->list($request->user(), $request->validated('type'), $request->validated('search')),
            __('agreement.retrieved_successfully')
        );
    }

    /**
     * The Plan & Fee dynamic values the builder offers for this type of
     * agreement, each with the price signing fills it with (AgreementPlanFields).
     */
    public function mergeFields(AgreementMergeFieldsRequest $request)
    {
        $type = $request->validated('type');

        return ApiResponse::success(
            array_merge(
                (new AgreementCommissionFields())->fields($type),
                (new AgreementEnrollmentFields())->fields($type),
                (new AgreementPlanFields())->fields($type)
            ),
            __('agreement.retrieved_successfully')
        );
    }

    /**
     * Update Agreement Status (Active/Inactive)
     */
    public function updateStatus(Request $request, $id)
    {
        $partnerId = $this->agreementOwnerId($request);

        try {
            $agreement = $this->agreementService->updateTemplateStatus($id, $partnerId, $request->is_active);
        } catch (\Exception $e) {
            return ApiResponse::serverError(__('agreement.status_update_failed') . ': ' . $e->getMessage());
        }

        $this->recordConfigChange(
            $request,
            PartnerConfigurationChangeRecorder::AREA_AGREEMENT,
            "turned the {$agreement->slug} agreement " . ($agreement->is_active ? 'on' : 'off')
        );

        return ApiResponse::success(
            new AgreementTemplateResource($agreement),
            __('agreement.status_updated_successfully')
        );
    }
}
