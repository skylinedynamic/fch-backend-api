<?php

namespace App\Http\Controllers\Api\V1\FCH\Admin\Partner;

use App\Services\FCH\Common\Notification\Notifier;
use Throwable;
use App\Helpers\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\Controller;
use App\Services\FCH\Partner\PartnerService;
use App\Http\Resources\FCH\Partner\PartnerResource;
use App\Http\Resources\FCH\Partner\TenantConfigResource;
use App\Http\Resources\FCH\Partner\PartnerDetailsResource;
use App\Http\Requests\Api\V1\FCH\Admin\Partner\FetchPartner;
use App\Http\Requests\Api\V1\FCH\Admin\Partner\StorePartnerRequest;
use App\Http\Requests\Api\V1\FCH\Admin\Partner\UpdatePartnerRequest;
use App\Http\Requests\Api\V1\FCH\Admin\Partner\UpdatePartnerCommissionRequest;
use App\Http\Requests\Api\V1\FCH\Admin\Partner\UpdateTenantConfigRequest;
use App\Mail\AccountCreateNotificationMail;
use App\Http\Requests\Api\V1\FCH\Admin\Partner\ArchivePartnerRequest;
use App\Http\Requests\Api\V1\FCH\Admin\Partner\UpdatePartnerStatusAgentRequest;
use App\Http\Resources\FCH\Partner\PartnerStatusAgentResource;

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
                // The list below is a whitelist: a filter missing from it is
                // silently dropped however well it validates, which is exactly how
                // archived and user_status went nowhere.
                'archived' => $input['archived'] ?? null,
                'status' => $this->normalizeFilterValue($input['status'] ?? null),
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
            return ApiResponse::notFound(__('partner.error.not_found'));
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
            [
                'id' => $updatedPartner->id,
                'full_name' => $updatedPartner->full_name,
                'company_name' => $updatedPartner->company_name,
                'email' => $updatedPartner->email,
                // A new sign-in email awaiting confirmation (the email above is
                // still the one in effect until it's confirmed).
                'pending_email' => $updatedPartner->partnerUser?->pending_email,
                'phone_number' => $updatedPartner->phone_number,
                'state' => $updatedPartner->state,
                'city' => $updatedPartner->city,
                'country' => $updatedPartner->country,
                'country_code' => $updatedPartner->country_code,
                'postal_code' => $updatedPartner->postal_code,
                'address_line_1' => $updatedPartner->address_line_1,
                'address_line_2' => $updatedPartner->address_line_2,
                'joining_date' => $updatedPartner->joining_date,
                'tax_id' => $updatedPartner->tax_id,
                'website' => $updatedPartner->website,
                'partner_image' => $updatedPartner->partner_image,
                'company_logo' => $updatedPartner->company_logo,
                'assigned_user_id' => $updatedPartner->assign_to,
                'contact_person' => $updatedPartner->contact_person,
                'contact_person_email' => $updatedPartner->contact_person_email,
                'contact_person_phone_number' => $updatedPartner->contact_person_phone_number,
                'contact_person_relation' => $updatedPartner->contact_person_relation,
                'partner_domain' => $updatedPartner->partner_domain,
                'borrower_domain' => $updatedPartner->borrower_domain,
                'status' => $updatedPartner->status,
                'is_free_trail' => (bool) ($updatedPartner->is_free_trail ?? false),
                'free_trail_end_date' => $updatedPartner->free_trail_end_date,
            ],
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
     * The Status & Agent tab: the partner's status and its agent's contact details.
     */
    public function statusAgent(int $id)
    {
        $partner = $this->partnerService->findStatusAgent($id);

        if (!$partner) {
            return ApiResponse::notFound(__('partner.error.not_found'));
        }

        return ApiResponse::success(
            (new PartnerStatusAgentResource($partner))->resolve(),
            __('partner.success.retrieved')
        );
    }

    /**
     * Save the Status & Agent tab: only the status and agent change.
     */
    public function updateStatusAgent(UpdatePartnerStatusAgentRequest $request, int $id)
    {
        $partner = $this->partnerService->findById($id);

        if (!$partner) {
            return ApiResponse::notFound(__('partner.error.not_found'));
        }

        try {
            $updated = $this->partnerService->updateStatusAgent($partner, $request->validated());

            return ApiResponse::success(
                (new PartnerStatusAgentResource($updated))->resolve(),
                __('partner.success.status_agent_updated')
            );
        } catch (Throwable $e) {
            return ApiResponse::error(__('partner.error.status_agent_update_failed'), 500);
        }
    }

    /**
     * Partner profile › Commissions: set the partner's Funding commission
     * tiers - its share (%) of the success fee by funded-deal count. The
     * funding application's commission split opens at the tier for that deal.
     */
    public function updateCommission(UpdatePartnerCommissionRequest $request, int $id)
    {
        $partner = $this->partnerService->findById($id);

        if (!$partner) {
            return ApiResponse::notFound(__('partner.error.not_found'));
        }

        $partner = $this->partnerService->updateCommission($partner, $request->validated('tiers'));

        return ApiResponse::success(
            $this->partnerService->commissionOf($partner),
            __('partner.success.commission_updated')
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
            Log::error('Tenant config update failed', ['error' => $e->getMessage()]);

            return ApiResponse::error(__('partner.error.tenant_config_update_failed'), 400);
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
            return ApiResponse::serverError(__('partner.borrowers_fetch_failed'));
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
            app(Notifier::class)->send('user.account.created', $user,
                mail: (new AccountCreateNotificationMail($data))->to($user->email));
        } catch (Throwable $e) {
            Log::error(__('partner.log.account_create_notification_failed'), [
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
            Log::error('Partner delete failed', ['error' => $e->getMessage()]);

            return ApiResponse::error(__('partner.error.delete_failed'), 400);
        }
    }

    /**
     * Archive partners: take them out of the working list without deleting them.
     *
     * Presentation only - `status` is untouched, so an archived partner keeps
     * whatever access and billing they had.
     */
    public function archive(ArchivePartnerRequest $request)
    {
        try {
            return $this->partnerService->archive($request->validated()['ids']);
        } catch (\Throwable $e) {
            Log::error('Partner archive failed', ['error' => $e->getMessage()]);

            return ApiResponse::error(__('partner.error.archive_failed'), 400);
        }
    }

    /** Restore archived partners to the working list. */
    public function unarchive(ArchivePartnerRequest $request)
    {
        try {
            return $this->partnerService->unarchive($request->validated()['ids']);
        } catch (\Throwable $e) {
            Log::error('Partner restore failed', ['error' => $e->getMessage()]);

            return ApiResponse::error(__('partner.error.unarchive_failed'), 400);
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
            Log::error('Deletable check failed', ['error' => $e->getMessage()]);

            return ApiResponse::error(__('partner.error.deletable_check_failed'), 400);
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

            return ApiResponse::success(['next_id' => $nextId], __('partner.next_id_retrieved'));
        } catch (\Throwable $e) {
            return ApiResponse::serverError(__('partner.next_id_failed'), 500);
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
