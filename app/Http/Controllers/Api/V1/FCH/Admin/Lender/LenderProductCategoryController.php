<?php

namespace App\Http\Controllers\Api\V1\FCH\Admin\Lender;

use App\Http\Controllers\Controller;
use App\Models\Lender\LenderProductCategory;
use Illuminate\Http\Request;
use App\Helpers\ApiResponse;
use App\Http\Requests\Api\V1\FCH\Admin\Lender\StoreLenderProductCategoryRequest;
use App\Http\Requests\Api\V1\FCH\Admin\Lender\UpdateLenderProductCategoryRequest;

class LenderProductCategoryController extends Controller
{
    public function index()
    {
        $categories = LenderProductCategory::orderBy('sort_order', 'asc')
            ->orderBy('name', 'asc')
            ->get();
            
        return ApiResponse::success($categories, __('lender.category_fetched'));
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

    public function destroy($id)
    {
        $category = LenderProductCategory::find($id);

        if (!$category) {
            return ApiResponse::error('Not found', 404);
        }

        $category->delete();

        return ApiResponse::success(null, __('lender.category_deleted'));
    }
}
