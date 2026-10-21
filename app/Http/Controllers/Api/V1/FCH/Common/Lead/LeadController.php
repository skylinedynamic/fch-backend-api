<?php

namespace App\Http\Controllers\Api\V1\FCH\Common\Lead;

use App\Models\Lead\Lead;
use App\Exceptions\LeadConvertedException;
use App\Helpers\ApiResponse;
use App\Models\Partner\Partner;
use App\Models\Borrower\Borrower;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\Controller;
use App\Http\Resources\FCH\Lead\LeadResource;
use App\Services\FCH\Common\Lead\LeadService;
use Symfony\Component\HttpFoundation\Response;
use App\Http\Resources\FCH\Partner\PartnerResource;
use App\Http\Resources\FCH\Borrower\BorrowerResource;
use App\Http\Resources\FCH\Lead\ConvertedBorrowerResource;
use App\Http\Requests\Api\V1\FCH\Common\Lead\FetchLeadRequest;
use App\Http\Requests\Api\V1\FCH\Common\Lead\StoreLeadDetailsRequest;
use App\Http\Requests\Api\V1\FCH\Common\Lead\UpdateLeadDetailsRequest;
use App\Http\Requests\Api\V1\FCH\Common\Lead\UpdateLeadContactInfoRequest;
use App\Http\Requests\Api\V1\FCH\Common\Lead\ConvertLeadRequest;
use App\Http\Requests\Api\V1\FCH\Common\Lead\DeleteLeadsRequest;

class LeadController extends Controller
{
    public function __construct(protected LeadService $leadService)
    {
    }

    /**
     * List Leads
     */
    public function index(FetchLeadRequest $request)
    {
        $this->checkWhiteLabelConfiguration();
        try {
            return $this->leadService->list($request->validated());
        } catch (\Exception $e) {
            return ApiResponse::serverError(__('lead.lead_list_failed'));
        }
    }

    /**
     * Lead Details
     */
    public function show($id)
    {
        $lead = $this->visibleLead($id);
        if (!$lead) {
            return ApiResponse::notFound(__('lead.lead_not_found'));
        }

        try {
            return ApiResponse::success(new LeadResource($lead), __('lead.lead_details'));
        } catch (\Exception $e) {
            return ApiResponse::error(__('lead.lead_not_found'));
        }
    }

    /**
     * Store Lead Details
     */
    public function storeDetails(StoreLeadDetailsRequest $request)
    {
        $this->checkWhiteLabelConfiguration();
        try {
            $lead = $this->leadService->storeDetails($request->validated());
            return ApiResponse::success(new LeadResource($lead), __('lead.lead_created'), Response::HTTP_CREATED);
        } catch (\Exception $e) {
            return ApiResponse::serverError(__('lead.lead_create_failed'));
        }
    }

    /**
     * Update Lead Details
     */
    public function updateDetails(UpdateLeadDetailsRequest $request, $id)
    {
        $this->checkWhiteLabelConfiguration();

        $lead = $this->visibleLead($id);
        if (!$lead) {
            return ApiResponse::notFound(__('lead.lead_not_found'));
        }

        try {
            $lead = $this->leadService->updateDetails($lead, $request->validated());
            return ApiResponse::success(new LeadResource($lead), __('lead.lead_updated'));
        } catch (\Exception $e) {
            return ApiResponse::serverError(__('lead.lead_update_failed'));
        }
    }

    /**
     * Update Lead Contact Info
     */
    public function updateContactInfo(UpdateLeadContactInfoRequest $request, $id)
    {
        $this->checkWhiteLabelConfiguration();

        $lead = $this->visibleLead($id);
        if (!$lead) {
            return ApiResponse::notFound(__('lead.lead_not_found'));
        }

        try {
            $lead = $this->leadService->updateContactInfo($lead, $request->validated());
            return ApiResponse::success(new LeadResource($lead), __('lead.lead_updated'));
        } catch (\Exception $e) {
            return ApiResponse::serverError(__('lead.lead_update_failed'));
        }
    }

    /**
     * A lead the caller may see, or null - every partner's for the host, a
     * partner's own book otherwise; the same scope the lead list applies.
     */
    private function visibleLead($id): ?Lead
    {
        return Lead::visibleTo(auth()->user())->find($id);
    }

    /**
     * Delete one lead or many - the only lead delete. Only leads the caller
     * may see are deleted, each logged; other ids are skipped, and none of
     * them found is a 404.
     */
    public function multipleDelete(DeleteLeadsRequest $request)
    {
        $this->checkWhiteLabelConfiguration();

        try {
            $deleted = $this->leadService->deleteMultiple($request->validated()['ids'], auth()->user());
        } catch (LeadConvertedException $e) {
            // Converted leads are kept - say which, and change nothing.
            return ApiResponse::error($e->getMessage(), 422, ['converted' => $e->converted]);
        } catch (\Exception $e) {
            Log::error('Lead multiple delete failed', ['error' => $e->getMessage()]);

            return ApiResponse::error(__('lead.multiple_delete_failed'), 422);
        }

        if ($deleted === 0) {
            return ApiResponse::notFound(__('lead.lead_not_found'));
        }

        return ApiResponse::success(['deleted' => $deleted], __('lead.lead_deleted'));
    }

    /**
     * Fetch Leads
     */
    public function fetch(FetchLeadRequest $request)
    {
        try {
            return $this->leadService->list($request->validated());
        } catch (\Exception $e) {
            return ApiResponse::serverError(__('lead.lead_fetch_failed'));
        }
    }

    /**
     * Convert Lead (Generic)
     */
    public function convert(ConvertLeadRequest $request, $id)
    {
        $this->checkWhiteLabelConfiguration();

        try {
            $lead = $this->visibleLead($id);

            if (!$lead) {
                return ApiResponse::error(__('lead.lead_not_belongs_to_partner'), 404);
            }

            $result = $this->leadService->convert($lead, $request->validated());

            if ($result instanceof Borrower) {
                return ApiResponse::success(new ConvertedBorrowerResource($result), __('lead.converted_to_borrower'));
            } elseif ($result instanceof Partner) {
                return ApiResponse::success(new PartnerResource($result), __('lead.converted_to_partner'));
            }

            return ApiResponse::success($result, __('lead.conversion_success'));
        } catch (\Exception $e) {
            return ApiResponse::serverError($e->getMessage() ?: __('lead.conversion_failed'));
        }
    }

}
