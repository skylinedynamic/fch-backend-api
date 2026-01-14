<?php

namespace App\Http\Controllers\Api\V1\FCH\Common\Lead;

use App\Models\Lead\Lead;
use App\Helpers\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\Controller;
use App\Http\Resources\FCH\Lead\LeadResource;
use App\Services\FCH\Common\Lead\LeadService;
use Symfony\Component\HttpFoundation\Response;
use App\Http\Resources\FCH\Partner\PartnerResource;
use App\Http\Resources\FCH\Borrower\BorrowerResource;
use App\Http\Requests\Api\V1\FCH\Common\Lead\FetchLeadRequest;
use App\Http\Requests\Api\V1\FCH\Common\Lead\StoreLeadRequest;
use App\Http\Requests\Api\V1\FCH\Common\Lead\UpdateLeadRequest;

class LeadController extends Controller
{
    public function __construct(protected LeadService $leadService) {}

    /**
     * List Leads
     */
    public function index(FetchLeadRequest $request)
    {
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
        try {
            $lead = $this->leadService->find($id);
            return ApiResponse::success(new LeadResource($lead), __('lead.lead_details'));
        } catch (\Exception $e) {
            return ApiResponse::error(__('lead.lead_not_found'));
        }
    }

    /**
     * Store Lead
     */
    public function store(StoreLeadRequest $request)
    {
        try {
            $lead = $this->leadService->create($request->validated());
            return ApiResponse::success(new LeadResource($lead), __('lead.lead_created'), Response::HTTP_CREATED);
        } catch (\Exception $e) {
            return ApiResponse::serverError(__('lead.lead_create_failed'));
        }
    }

    /**
     * Update Lead
     */
    public function update(UpdateLeadRequest $request, $id)
    {
        try {
            $lead = Lead::findOrFail($id);
            $lead = $this->leadService->update($lead, $request->validated());
            return ApiResponse::success(new LeadResource($lead), __('lead.lead_updated'));
        } catch (\Exception $e) {
            return ApiResponse::serverError(__('lead.lead_update_failed'));
        }
    }

    /**
     * Delete Lead
     */
    public function destroy($id)
    {
        try {
            $lead = Lead::findOrFail($id);
            $this->leadService->delete($lead);
            return ApiResponse::success([], __('lead.lead_deleted'));
        } catch (\Exception $e) {
            return ApiResponse::serverError(__('lead.lead_update_failed'));
        }
    }

    /**
     * Multiple Delete
     */
    public function multipleDelete(Request $request)
    {
        $ids = $request->input('ids', []);
        if ($this->leadService->deleteMultiple($ids)) {
            return ApiResponse::success([], __('lead.lead_deleted'));
        }
        return ApiResponse::error(__('lead.multiple_delete_failed'), 422);
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
    public function convert($id)
    {
        Log::info('Convert lead: ' . $id);
        try {
            $lead = Lead::findOrFail($id);
            $result = $this->leadService->convert($lead);

            if ($result instanceof Borrower) {
                return ApiResponse::success(new BorrowerResource($result), __('lead.converted_to_borrower'));
            } elseif ($result instanceof Partner) {
                return ApiResponse::success(new PartnerResource($result), __('lead.converted_to_partner'));
            }

            return ApiResponse::success($result, __('lead.conversion_success'));
        } catch (\Exception $e) {
            return ApiResponse::serverError($e->getMessage() ?: __('lead.conversion_failed'));
        }
    }
}
