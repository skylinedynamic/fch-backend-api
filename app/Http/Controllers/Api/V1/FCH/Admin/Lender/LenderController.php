<?php

namespace App\Http\Controllers\Api\V1\FCH\Admin\Lender;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\FCH\Admin\Lender\FetchLenderRequest;
use App\Models\Lender\Lender;
use App\Http\Requests\Api\V1\FCH\Admin\Lender\StoreLenderRequest;
use App\Http\Requests\Api\V1\FCH\Admin\Lender\UpdateLenderRequest;
use App\Http\Resources\FCH\Lender\LenderResource;
use App\Services\FCH\Admin\Lender\LenderService;
use App\Helpers\ApiResponse;
use App\Exceptions\LenderInUseException;
use App\Http\Requests\Api\V1\FCH\Admin\Lender\DeleteLendersRequest;
use App\Http\Requests\Api\V1\FCH\Admin\Lender\CheckLenderDuplicatesRequest;
use Exception;
use Illuminate\Support\Facades\Log;

class LenderController extends Controller
{
    protected $lenderService;

    public function __construct(LenderService $lenderService)
    {
        $this->lenderService = $lenderService;
    }
    public function index(FetchLenderRequest $request)
    {
        try {
            return $this->lenderService->list($request->validated());
        } catch (\Exception $e) {
            return ApiResponse::serverError(__('lender.list_failed'));
        }
    }

    public function getAll()
    {
        $lenders = $this->lenderService->getAllActiveLenders();
        return ApiResponse::success($lenders, __('lender.fetched_successfully'));
    }

    /** Lenders that already have this name or legal name - for Add/Edit Lender's warning. */
    public function checkDuplicates(CheckLenderDuplicatesRequest $request)
    {
        try {
            return ApiResponse::success($this->lenderService->duplicates($request->validated()), __('lender.duplicates_checked'));
        } catch (Exception $e) {
            Log::error('Lender duplicate check failed: ' . $e->getMessage());

            return ApiResponse::serverError(__('lender.duplicates_check_failed'));
        }
    }

    public function store(StoreLenderRequest $request)
    {
        $validated = $request->validated();
        $lender = $this->lenderService->store($validated);

        return ApiResponse::success(new LenderResource($lender), __('lender.created_successfully'), 201);
    }

    public function show($id)
    {
        $lender = Lender::findOrFail($id);
        return ApiResponse::success(new LenderResource($lender), __('lender.fetched_successfully'));
    }

    public function update(UpdateLenderRequest $request, $id)
    {
        $validated = $request->validated();
        $lender = Lender::findOrFail($id);
        
        $lender = $this->lenderService->update($lender, $validated);

        return ApiResponse::success(new LenderResource($lender), __('lender.updated_successfully'));
    }

    public function destroy($id)
    {
        $lender = Lender::findOrFail($id);

        try {
            $this->lenderService->delete($lender);
        } catch (LenderInUseException $e) {
            return ApiResponse::error($e->getMessage(), 422, ['blocked' => $e->blocked]);
        }

        return ApiResponse::success(null, __('lender.deleted_successfully'));
    }

    /** Delete several lenders in one call - all or none. */
    public function multipleDelete(DeleteLendersRequest $request)
    {
        try {
            $deleted = $this->lenderService->deleteMultiple($request->validated()['ids']);
        } catch (LenderInUseException $e) {
            // Lenders still in use are kept - say which, and change nothing.
            return ApiResponse::error($e->getMessage(), 422, ['blocked' => $e->blocked]);
        } catch (\Exception $e) {
            Log::error('Lender multiple delete failed', ['error' => $e->getMessage()]);

            return ApiResponse::error(__('lender.delete_failed'), 400);
        }

        return ApiResponse::success(['deleted' => $deleted], __('lender.deleted_successfully'));
    }

    /** Whether each selected lender can be deleted, and why not. */
    public function checkDeletable(DeleteLendersRequest $request)
    {
        try {
            return ApiResponse::success($this->lenderService->checkDeletable($request->validated()['ids']), __('lender.fetched_successfully'));
        } catch (\Exception $e) {
            Log::error('Lender deletable check failed', ['error' => $e->getMessage()]);

            return ApiResponse::error(__('lender.deletable_check_failed'), 400);
        }
    }
}
