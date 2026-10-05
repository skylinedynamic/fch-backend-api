<?php

namespace App\Http\Controllers\Api\V1\FCH\Common\Compensation;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\FCH\Common\Compensation\CompensationRequest;
use App\Http\Requests\Api\V1\FCH\Common\Compensation\FetchCompensationRequest;
use App\Http\Resources\FCH\Compensation\CompensationResource;
use App\Models\Compensation\CompensationSetting;
use App\Services\FCH\Common\Compensation\CompensationService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * Compensation ("Paid By") setups, kept entirely separate from the employee
 * endpoints. The owning entity is identified by entity_type/entity_id in the
 * body rather than the path, so the same endpoints serve every entity that can
 * be paid — employees today, and partner-side salesmen once those land.
 */
class CompensationController extends Controller
{
    public function __construct(protected CompensationService $compensationService) {}

    /**
     * Fetch an entity's compensation setup.
     */
    public function show(FetchCompensationRequest $request): JsonResponse
    {
        $entity = $this->resolveEntity($request);

        if (!$entity) {
            return ApiResponse::forbidden(__('compensation.entity_not_found'));
        }

        try {
            return ApiResponse::success(
                new CompensationResource($this->compensationService->getFor($entity)),
                __('compensation.retrieved')
            );
        } catch (Throwable $e) {
            return ApiResponse::serverError(__('compensation.retrieve_failed'));
        }
    }

    /**
     * Save an entity's compensation setup — the one write endpoint, used for
     * both the first save and every later edit.
     *
     * A request always carries the complete setup and replaces what's stored,
     * so there's nothing for a separate update endpoint to do differently: the
     * number of rows varies with the selected services, and a setup of "None"
     * is legitimately zero rows, so no row count reliably says whether a setup
     * already exists.
     */
    public function store(CompensationRequest $request): JsonResponse
    {
        $entity = $this->resolveEntity($request);

        if (!$entity) {
            return ApiResponse::forbidden(__('compensation.entity_not_found'));
        }

        try {
            $rows = $this->compensationService->sync(
                $entity,
                $request->validated('paid_by'),
                $request->validated('compensations') ?? []
            );

            return ApiResponse::success(new CompensationResource($rows), __('compensation.saved'));
        } catch (Throwable $e) {
            return ApiResponse::serverError(__('compensation.save_failed'));
        }
    }

    /**
     * Resolve the owning entity from the validated slug/id, scoped to the
     * caller's partner — compensation is payroll data, so an id belonging to
     * another tenant reads as forbidden rather than not-found.
     */
    private function resolveEntity(Request $request): ?Model
    {
        $modelClass = CompensationSetting::ENTITY_TYPES[$request->input('entity_type')] ?? null;

        if (!$modelClass) {
            return null;
        }

        $entity = $modelClass::find($request->input('entity_id'));

        if (!$entity || $entity->partner_id !== $request->user()->partner_id) {
            return null;
        }

        return $entity;
    }
}
