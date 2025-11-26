<?php

namespace App\Http\Controllers\Api\V1\FCH\Admin\Partner;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Services\FCH\Partner\PartnerService;
use App\Http\Resources\FCH\Partner\PartnerResource;
use App\Http\Requests\Api\V1\FCH\Admin\Partner\StorePartnerRequest;
use App\Http\Requests\Api\V1\FCH\Admin\Partner\UpdatePartnerRequest;
use App\Http\Requests\Api\V1\FCH\Admin\Partner\FetchPartner;

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
                'per_page'          => $perPage,
                'page'              => isset($input['page']) ? max(1, (int) $input['page']) : 1,
                'search'            => isset($input['search']) ? trim((string) $input['search']) : null,
                'partner_id'        => $input['partner_id'] ?? null,
                'assigned_user_id'  => $input['assigned_user_id'] ?? null,
                'filters'           => $input['filters'] ?? null, // array expected per request rules
                'sort_field'        => $input['sort_field'] ?? null,
                'sort_order'        => $input['sort_order'] ?? null,
            ];

            return $this->partnerService->list($filters);

        } catch (\Exception $e) {
            return ApiResponse::serverError(__('partner.error.list_failed'));
        }
    }

    /**
     * Store Partner.
     */
    public function store(StorePartnerRequest $request)
    {
        $partner = $this->partnerService->store($request->validated());


        return ApiResponse::success(
            new PartnerResource($partner),
            __('partner.success.created'),
            201
        );
    }

    /**
     * Update Update.
     */
    public function update(UpdatePartnerRequest $request, int $id)
    {
        $partner = $this->partnerService->findById($id);

        if (!$partner) {
            return ApiResponse::notFound('Partner not found');
        }

        $updatedPartner = $this->partnerService->update($partner, $request->validated());

        return ApiResponse::success(
            new PartnerResource($updatedPartner),
            __('partner.success.updated'),
        );
    }

    /**
     * Get all users with entity_type = Employee or NULL.
     */
    public function getAllUsers(Request $request)
    {
        $users = $this->partnerService->getAllUsers();

        return ApiResponse::success(
            $users,
            'Users retrieved successfully'
        );
    }
}