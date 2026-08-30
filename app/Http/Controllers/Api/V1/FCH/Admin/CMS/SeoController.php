<?php

namespace App\Http\Controllers\Api\V1\FCH\Admin\CMS;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\FCH\Admin\CMS\SeoRequest;
use App\Services\FCH\Admin\CMS\SeoService;
use App\Helpers\ApiResponse;
use Illuminate\Http\Request;

class SeoController extends Controller
{
    protected SeoService $seoService;

    public function __construct(SeoService $seoService)
    {
        $this->seoService = $seoService;
    }

    public function index(Request $request)
    {
        $filters = [
            'search' => $request->search,
            'per_page' => $request->per_page,
            'sort_field' => $request->sort_field,
            'sort_order' => $request->sort_order,
            'partner_id' => $request->user() ? $request->user()->partner_id : null,
        ];
        $result = $this->seoService->list($filters);
        $settings = $result['paginator'];

        return ApiResponse::successWithPagination(
            $settings->items(),
            [
                'total'        => $settings->total(),
                'per_page'     => $settings->perPage(),
                'current_page' => $settings->currentPage(),
                'last_page'    => $settings->lastPage(),
            ],
            'SEO settings retrieved successfully'
        );
    }

    public function getAll(Request $request)
    {
        $partnerId = $request->user() ? $request->user()->partner_id : null;
        $settings = $this->seoService->getAll($partnerId);
        return ApiResponse::success($settings, 'All SEO settings retrieved successfully');
    }

    public function store(SeoRequest $request)
    {
        $data = $request->validated();
        $data['partner_id'] = $request->user()->partner_id;
        $data['created_by'] = $request->user()->id;

        $setting = $this->seoService->store($data);

        return ApiResponse::success($setting, 'SEO setting created successfully', 201);
    }

    public function show($id)
    {
        $setting = $this->seoService->findById($id);
        if (!$setting) return ApiResponse::notFound('SEO setting not found');
        return ApiResponse::success($setting, 'SEO setting retrieved successfully');
    }

    public function update(SeoRequest $request, $id)
    {
        $setting = $this->seoService->findById($id);
        if (!$setting) return ApiResponse::notFound('SEO setting not found');

        $data = $request->validated();
        $data['updated_by'] = $request->user()->id;

        $updatedSetting = $this->seoService->update($setting, $data);

        return ApiResponse::success($updatedSetting, 'SEO setting updated successfully');
    }

    public function destroy(Request $request)
    {
        $ids = $request->ids;
        if (!is_array($ids)) $ids = [$ids];
        return $this->seoService->delete($ids);
    }
}
