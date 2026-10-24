<?php

namespace App\Http\Controllers\Api\V1\FCH\Admin\Funding;

use App\Exceptions\FundingSequenceInUseException;
use App\Helpers\ApiResponse;
use App\Http\Requests\Api\V1\FCH\Admin\Funding\DeleteFundingSequencingsRequest;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\FCH\Admin\Funding\FetchFundingSequencingRequest;
use App\Http\Requests\Api\V1\FCH\Admin\Funding\StoreFundingSequencingRequest;
use App\Http\Requests\Api\V1\FCH\Admin\Funding\UpdateFundingSequencingRequest;
use App\Http\Requests\Api\V1\FCH\Admin\Funding\UpdateFundingSequencingDisplayRequest;
use App\Http\Requests\Api\V1\FCH\Admin\Funding\UpdateFundingSequencingVisibilityRequest;
use App\Http\Requests\Api\V1\FCH\Admin\Funding\DeleteFundingSequencingVisibilityRequest;
use App\Http\Resources\FCH\Funding\FundingSequencingResource;
use App\Services\FCH\Admin\Funding\FundingSequencingService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class FundingSequencingController extends Controller
{
    protected $service;

    public function __construct(FundingSequencingService $service)
    {
        $this->service = $service;
    }

    public function index(FetchFundingSequencingRequest $request): JsonResponse
    {
        try {
            return $this->service->list($request->validated());
        } catch (\Exception $e) {
            return ApiResponse::serverError(__('funding_sequencing.list_failed'));
        }
    }
    
    public function grouped(): JsonResponse
    {
        $data = $this->service->getGroupedSequencings();
        return ApiResponse::success($data, __('funding_sequencing.grouped_list_fetched'));
    }

    public function store(StoreFundingSequencingRequest $request): JsonResponse
    {
        $sequencing = $this->service->store($request->validated());

        return ApiResponse::success(
            new FundingSequencingResource($sequencing),
            __('funding_sequencing.created'),
            201
        );
    }

    public function show($id): JsonResponse
    {
        $sequencing = $this->service->show($id);

        return ApiResponse::success(
            new FundingSequencingResource($sequencing),
            __('funding_sequencing.fetched')
        );
    }

    public function update(UpdateFundingSequencingRequest $request, $id): JsonResponse
    {
        $sequencing = $this->service->update($id, $request->validated());

        return ApiResponse::success(
            new FundingSequencingResource($sequencing),
            __('funding_sequencing.updated')
        );
    }

    public function updateDisplay(UpdateFundingSequencingDisplayRequest $request, $id): JsonResponse
    {
        $sequencing = $this->service->update($id, $request->validated());

        return ApiResponse::success(
            new FundingSequencingResource($sequencing),
            __('funding_sequencing.updated')
        );
    }

    public function updateVisibility(UpdateFundingSequencingVisibilityRequest $request, $id): JsonResponse
    {
        $sequencing = $this->service->update($id, $request->validated());

        return ApiResponse::success(
            new FundingSequencingResource($sequencing),
            __('funding_sequencing.updated')
        );
    }

    public function updateSingleLenderVisibility(UpdateFundingSequencingVisibilityRequest $request, $id, $lenderId): JsonResponse
    {
        $sequencing = $this->service->updateSingleLenderVisibility($id, $lenderId, $request->validated());

        return ApiResponse::success(
            new FundingSequencingResource($sequencing),
            __('funding_sequencing.updated')
        );
    }

    public function updateSingleCategoryVisibility(UpdateFundingSequencingVisibilityRequest $request, $id, $lenderId, $categoryId): JsonResponse
    {
        $sequencing = $this->service->updateSingleCategoryVisibility($id, $lenderId, $categoryId, $request->validated());

        return ApiResponse::success(
            new FundingSequencingResource($sequencing),
            __('funding_sequencing.updated')
        );
    }

    public function deleteVisibilities(DeleteFundingSequencingVisibilityRequest $request, $id): JsonResponse
    {
        $this->service->deleteVisibilities($id, $request->visibility_ids);

        return ApiResponse::success(null, __('funding_sequencing.visibility_deleted'));
    }

    public function updateStatus(Request $request, $id): JsonResponse
    {
        $request->validate(['status' => 'required|string|in:Active,Inactive']);
        $sequencing = $this->service->updateStatus($id, $request->status);

        return ApiResponse::success(
            new FundingSequencingResource($sequencing),
            __('funding_sequencing.status_updated')
        );
    }

    public function fetchVisibilities(Request $request, $id): JsonResponse
    {
        return $this->service->getPaginatedVisibilities($id, $request->all());
    }

    public function destroy($id): JsonResponse
    {
        try {
            $this->service->delete($id);
        } catch (FundingSequenceInUseException $e) {
            return ApiResponse::error($e->getMessage(), 422, ['blocked' => $e->blocked]);
        }

        return ApiResponse::success(
            null,
            __('funding_sequencing.deleted')
        );
    }

    /** Delete several funding sequences in one call - all or none. */
    public function multipleDelete(DeleteFundingSequencingsRequest $request): JsonResponse
    {
        try {
            $deleted = $this->service->deleteMultiple($request->validated()['ids']);
        } catch (FundingSequenceInUseException $e) {
            // Sequences used by a funding request are kept - say which, and change nothing.
            return ApiResponse::error($e->getMessage(), 422, ['blocked' => $e->blocked]);
        } catch (\Exception $e) {
            Log::error('Funding sequence multiple delete failed', ['error' => $e->getMessage()]);

            return ApiResponse::error(__('funding_sequencing.delete_failed'), 400);
        }

        return ApiResponse::success(['deleted' => $deleted], __('funding_sequencing.deleted'));
    }

    /** Whether each selected funding sequence can be deleted, and why not. */
    public function checkDeletable(DeleteFundingSequencingsRequest $request): JsonResponse
    {
        try {
            return ApiResponse::success($this->service->checkDeletable($request->validated()['ids']), __('funding_sequencing.list_fetched'));
        } catch (\Exception $e) {
            Log::error('Funding sequence deletable check failed', ['error' => $e->getMessage()]);

            return ApiResponse::error(__('funding_sequencing.deletable_check_failed'), 400);
        }
    }
}
