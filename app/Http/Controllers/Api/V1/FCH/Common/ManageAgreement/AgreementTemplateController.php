<?php

namespace App\Http\Controllers\Api\V1\FCH\Common\ManageAgreement;

use App\Http\Controllers\Controller;
use App\Helpers\ApiResponse;
use App\Http\Requests\Api\V1\FCH\Common\ManageAgreement\AgreementTemplateStoreRequest;
use App\Http\Requests\Api\V1\FCH\Common\ManageAgreement\AgreementTemplateShowRequest;
use App\Http\Resources\FCH\Common\ManageAgreement\AgreementTemplateResource;
use App\Services\FCH\Common\ManageAgreement\AgreementService;
use App\Services\FCH\Partner\PartnerConfigurationChangeRecorder;
use App\Models\Partner\Partner;
use App\Services\FCH\Common\ManageAgreement\AgreementMergeFields;
use App\Traits\ResolvesConfigPartner;
use Illuminate\Http\Request;

/**
 * A partner's agreement templates. The signed-in user's own partner, or - from
 * Partner profile › Configuration - another partner named with `partner_id`.
 */
class AgreementTemplateController extends Controller
{
    use ResolvesConfigPartner;

    /** Who may name another partner's agreements: a host user holding it. */
    private const ON_BEHALF_PERMISSION = 'manage partner configuration';

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
        $partnerId = $this->templateOwnerId($request);

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
        $partnerId = $this->templateOwnerId($request);

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
        $partnerId = $this->templateOwnerId($request);

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
    public function previewValues(Request $request)
    {
        $partner = Partner::find($this->templateOwnerId($request));

        return ApiResponse::success((new AgreementMergeFields())->ownerValues($partner), __('agreement.retrieved_successfully'));
    }

    /**
     * Update Agreement Status (Active/Inactive)
     */
    public function updateStatus(Request $request, $id)
    {
        $partnerId = $this->templateOwnerId($request);

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

    /**
     * Whose templates these are. A partner's agreement is what its borrowers
     * sign, so unlike the other settings endpoints naming another partner is
     * checked here: only a host user with the on-behalf permission may.
     * Resolved outside the actions' try blocks so a 403/404 is not turned
     * into a server error.
     */
    private function templateOwnerId(Request $request): int
    {
        if ($this->namesConfigPartner($request)) {
            $user = $request->user();
            abort_unless(
                $user?->partner?->is_host && $user->can(self::ON_BEHALF_PERMISSION),
                403,
                __('partner.configuration_forbidden')
            );
        }

        return (int) $this->configPartnerId($request);
    }
}
