<?php

namespace App\Http\Controllers\Api\V1\FCH\Common\ManageAgreement;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\FCH\Common\ManageAgreement\AgreementSavedTemplateIndexRequest;
use App\Http\Requests\Api\V1\FCH\Common\ManageAgreement\AgreementSavedTemplateStoreRequest;
use App\Http\Resources\FCH\Common\ManageAgreement\AgreementSavedTemplateResource;
use App\Services\FCH\Common\ManageAgreement\AgreementSavedTemplateService;
use App\Traits\ResolvesAgreementOwner;
use Illuminate\Http\Request;

/**
 * Agreement Builder › Save as Template, and the saved templates the Templates
 * dialog offers beside the built-in ones. The signed-in user's own partner's,
 * or - from Partner profile › Configuration - another partner's named with
 * `partner_id` (ResolvesAgreementOwner).
 */
class AgreementSavedTemplateController extends Controller
{
    use ResolvesAgreementOwner;

    public function __construct(private readonly AgreementSavedTemplateService $templates)
    {
    }

    public function index(AgreementSavedTemplateIndexRequest $request)
    {
        $partnerId = $this->agreementOwnerId($request);

        return ApiResponse::success(
            AgreementSavedTemplateResource::collection($this->templates->list($partnerId, $request->validated('slug'))),
            __('agreement.saved_template.retrieved')
        );
    }

    public function store(AgreementSavedTemplateStoreRequest $request)
    {
        $partnerId = $this->agreementOwnerId($request);

        $template = $this->templates->create($request->validated(), $partnerId, $request->user()?->id);

        return ApiResponse::success(new AgreementSavedTemplateResource($template), __('agreement.saved_template.saved'));
    }

    public function show(Request $request, int $id)
    {
        $partnerId = $this->agreementOwnerId($request);

        return ApiResponse::success(
            (new AgreementSavedTemplateResource($this->templates->find($partnerId, $id)))->withBlocks(),
            __('agreement.saved_template.retrieved')
        );
    }

    public function destroy(Request $request, int $id)
    {
        $partnerId = $this->agreementOwnerId($request);

        $this->templates->delete($partnerId, $id);

        return ApiResponse::success(null, __('agreement.saved_template.deleted'));
    }
}
