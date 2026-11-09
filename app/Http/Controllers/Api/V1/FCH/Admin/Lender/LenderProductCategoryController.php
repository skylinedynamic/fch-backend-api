<?php

namespace App\Http\Controllers\Api\V1\FCH\Admin\Lender;

use App\Exceptions\LenderProductCategoryInUseException;
use App\Http\Controllers\Controller;
use App\Models\Lender\LenderProductCategory;
use App\Services\FCH\Admin\Lender\LenderService;
use Illuminate\Http\Request;
use App\Helpers\ApiResponse;
use App\Http\Requests\Api\V1\FCH\Admin\Lender\StoreLenderProductCategoryRequest;
use App\Http\Requests\Api\V1\FCH\Admin\Lender\UpdateLenderProductCategoryRequest;

class LenderProductCategoryController extends Controller
{
    public function index(LenderService $lenderService)
    {
        // Each with funding_sequences_count, so a category in use is refused at once.
        return ApiResponse::success($lenderService->productCategoriesWithUsage(), __('lender.category_fetched'));
    }

    public function store(StoreLenderProductCategoryRequest $request)
    {
        $data = $request->all();
        
        if (empty($data['sort_order'])) {
            $data['sort_order'] = LenderProductCategory::max('sort_order') + 1;
        }

        $category = LenderProductCategory::create($data);

        return ApiResponse::success($category, __('lender.category_created'), 201);
    }

    public function update(UpdateLenderProductCategoryRequest $request, $id)
    {
        $category = LenderProductCategory::find($id);

        if (!$category) {
            return ApiResponse::error('Not found', 404);
        }

        $category->update($request->all());

        return ApiResponse::success($category, __('lender.category_updated'));
    }

    public function destroy($id, LenderService $lenderService)
    {
        $category = LenderProductCategory::find($id);

        if (!$category) {
            return ApiResponse::error('Not found', 404);
        }

        try {
            $lenderService->deleteProductCategory($category);
        } catch (LenderProductCategoryInUseException $e) {
            // Used by a funding sequence - say which, and change nothing.
            return ApiResponse::error($e->getMessage(), 422, ['blocked' => $e->blocked]);
        }

        return ApiResponse::success(null, __('lender.category_deleted'));
    }
}
