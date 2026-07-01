<?php

namespace App\Http\Controllers\Api\V1\FCH\Admin\Partner;

use Throwable;
use App\Helpers\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Mail;
use App\Services\FCH\Partner\PartnerService;
use App\Http\Resources\FCH\Partner\PartnerResource;
use App\Http\Resources\FCH\Partner\TenantConfigResource;
use App\Http\Resources\FCH\Partner\PartnerDetailsResource;
use App\Http\Requests\Api\V1\FCH\Admin\Partner\FetchPartner;
use App\Http\Requests\Api\V1\FCH\Admin\Partner\StorePartnerRequest;
use App\Http\Requests\Api\V1\FCH\Admin\Partner\UpdatePartnerRequest;
use App\Http\Requests\Api\V1\FCH\Admin\Partner\UpdateTenantConfigRequest;
use App\Services\Global\Notification\Mail\AccountCreateNotificationMail;

class PartnerController extends Controller
{
    protected PartnerService $partnerService;

    public function __construct(PartnerService $partnerService)
    {
        $this->partnerService = $partnerService;
    }

    /**
     * Get Partners.
     */
    public function index(FetchPartner $request)
    {
        try {
            $input = $request->validated();

            // Sanitize and cap pagination to avoid heavy payloads
            $perPage = isset($input['per_page']) ? (int) $input['per_page'] : null;
            $perPage = $perPage !== null ? max(1, min($perPage, 100)) : null; // cap at 100

            $filters = [
                'per_page' => $perPage,
                'page' => isset($input['page']) ? max(1, (int) $input['page']) : 1,
                'search' => isset($input['search']) ? trim((string) $input['search']) : null,
                'partner_id' => $this->normalizeFilterValue($input['partner_id'] ?? null),
                'assigned_user_id' => $this->normalizeFilterValue($input['assigned_user_id'] ?? null),
                'is_free_trail' => $this->normalizeFilterValue($input['is_free_trail'] ?? null),
                'filters' => $input['filters'] ?? null, // array expected per request rules
                'sort_field' => $input['sort_field'] ?? null,
                'sort_order' => $input['sort_order'] ?? null,
            ];

            return $this->partnerService->list($filters);
        } catch (\Exception $e) {
            Log::error($e->getMessage());
            return ApiResponse::serverError(__('partner.error.list_failed'));
        }
    }

    /**
     * Store Partner.
     */
    public function store(StorePartnerRequest $request)
    {
        $data = $request->all();

        // Handle file uploads
        if ($request->hasFile('partner_image')) {
            $data['partner_image'] = $request->file('partner_image');
        }

        if ($request->hasFile('company_logo')) {
            $data['company_logo'] = $request->file('company_logo');
        }

        $result = $this->partnerService->store($data);
        $partner = $result['partner'];

        return ApiResponse::success(
            new PartnerResource($partner),
            __('partner.success.created'),
            201
        );
    }

    /**
     * Update Partner.
     */
    public function update(UpdatePartnerRequest $request, int $id)
    {
        $partner = $this->partnerService->findById($id);

        if (!$partner) {
            return ApiResponse::notFound('Partner not found');
        }

        $data = $request->validated();

        // Handle file uploads
        if ($request->hasFile('partner_image')) {
            $data['partner_image'] = $request->file('partner_image');
        }

        if ($request->hasFile('company_logo')) {
            $data['company_logo'] = $request->file('company_logo');
        }

        $updatedPartner = $this->partnerService->update($partner, $data);

        return ApiResponse::success(
            new PartnerResource($updatedPartner),
            __('partner.success.updated'),
        );
    }

    /**
     * Get Partner Details.
     */
    public function details(int $id)
    {
        $partner = $this->partnerService->getDetails($id);

        if (!$partner) {
            return ApiResponse::notFound(__('partner.error.not_found'));
        }

        return ApiResponse::success(
            new PartnerDetailsResource($partner),
            __('partner.success.retrieved')
        );
    }

    /**
     * Get Tenant Config.
     */
    public function getTenantConfig(int $id)
    {
        $partner = $this->partnerService->getTenantConfig($id);

        if (!$partner) {
            return ApiResponse::notFound(__('partner.error.not_found'));
        }

        return ApiResponse::success((new TenantConfigResource($partner))->resolve(), __('partner.success.tenant_config_retrieved'));
    }

    /**
     * Update Tenant Config.
     */
    public function updateTenantConfig(UpdateTenantConfigRequest $request, int $id)
    {
        try {
            $updatedPartner = $this->partnerService->updateTenantConfig($id, $request->validated());
            return ApiResponse::success((new TenantConfigResource($updatedPartner))->resolve(), __('partner.success.tenant_config_updated'));
        } catch (\Exception $e) {
            return ApiResponse::error($e->getMessage(), 400);
        }
    }


    /**
     * Get Borrowers List.
     */
    public function getBorrowers(Request $request)
    {
        try {
            $filters = [
                'per_page' => $request->input('per_page'),
                'page' => $request->input('page'),
                'search' => $request->input('search'),
                'partner_id' => $this->normalizeFilterValue($request->input('partner_id')),
                'sort_field' => $request->input('sort_field'),
                'sort_order' => $request->input('sort_order'),
                'filters' => $request->input('filters') ?? null,
            ];

            return $this->partnerService->getBorrowers($filters);
        } catch (\Exception $e) {
            return ApiResponse::serverError('Failed to fetch borrowers');
        }
    }

    /**
     * Account Ceated Email.
     */
    public function sendAccountCreatedMail($user, $businessInfo, string $plainPassword): void
    {
        $data = [
            'partner_id' => $user->partner_id,
            'user' => $user,
            'plain_password' => $plainPassword,
            'business_info' => [
                'company_name' => $businessInfo->company_name,
                'company_logo' => $businessInfo->company_logo,
                'email' => $businessInfo->email,
                'phone_number' => $businessInfo->phone_number,
                'address_line_1' => $businessInfo->address_line_1,
            ],
        ];

        try {
            Mail::to($user->email)->send(new AccountCreateNotificationMail($data));
        } catch (Throwable $e) {
            Log::error('📧 AccountCreatedNotificationMail failed', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Delete Partner
     */
    public function partnerDelete(Request $request)
    {
        try {
            return $this->partnerService->delete($request->ids);
        } catch (\Throwable $e) {
            return ApiResponse::error($e->getMessage(), 400);
        }
    }

    /**
     * Check if multiple partners are deletable (for modal)
     */
    public function checkDeletable(Request $request)
    {
        try {
            $results = $this->partnerService->checkDeletable($request->ids);
            return ApiResponse::success($results, __('partner.success.retrieved'));
        } catch (\Throwable $e) {
            return ApiResponse::error($e->getMessage(), 400);
        }
    }

    /**
     * Get Partner Count Statistics.
     */
    public function stats()
    {
        try {
            $data = $this->partnerService->getPartnerStats();

            return ApiResponse::success($data, __('partner.partner_stats_retrieved'));
        } catch (\Throwable $e) {
            return ApiResponse::serverError(__('partner.partner_stats_retrieved_failed'), 500);
        }
    }

    /**
     * Profile States Count
     */
    public function profileStatsCount(int $id)
    {
        $data = $this->partnerService->getPartnerProfileStats($id);

        return ApiResponse::success($data, __('partner.partner_stats_retrieved'));
    }

    /**
     * Get Next Id
     */
    public function getNextId()
    {
        try {
            $nextId = $this->partnerService->getNextPartnerId();

            return ApiResponse::success(['next_id' => $nextId], 'Next partner ID retrieved');
        } catch (\Throwable $e) {
            return ApiResponse::serverError('Failed to retrieve next partner ID', 500);
        }
    }

    /**
     * Normalize filter value (handles object {value: ...} or direct value)
     */
    private function normalizeFilterValue($value)
    {
        if (is_array($value) && array_key_exists('value', $value)) {
            return $value['value'];
        }

        return $value;
    }
}
