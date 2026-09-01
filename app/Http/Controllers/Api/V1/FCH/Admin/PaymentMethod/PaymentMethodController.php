<?php

namespace App\Http\Controllers\Api\V1\FCH\Admin\PaymentMethod;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\Partner\Partner;
use App\Services\FCH\Admin\PaymentMethod\PaymentMethodService;
use App\Http\Resources\FCH\PaymentMethod\PaymentProviderResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Exception;

class PaymentMethodController extends Controller
{
    public function __construct(
        protected PaymentMethodService $paymentMethodService
    ) {}

    /**
     * List all payment providers for host admin.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $user = auth()->user();
            if (!$user || $user->partner->is_host != 1 || $user->entity_type !== Partner::class) {
                return ApiResponse::error(__('payment_method.unauthorized'), 403);
            }

            $providers = $this->paymentMethodService->getAllProviders();

            return ApiResponse::success(
                PaymentProviderResource::collection($providers), 
                __('payment_method.retrieved')
            );
        } catch (Exception $e) {
            return ApiResponse::error($e->getMessage(), 400);
        }
    }

    /**
     * Update payment provider details (logo and status) for host admin.
     *
     * @param Request $request
     * @param int $id
     * @return JsonResponse
     */
    public function update(Request $request, $id): JsonResponse
    {
        try {
            $user = auth()->user();
            if (!$user || $user->partner->is_host != 1 || $user->entity_type !== Partner::class) {
                return ApiResponse::error(__('payment_method.unauthorized'), 403);
            }

            $provider = $this->paymentMethodService->updateProvider($id, $request->all());

            return ApiResponse::success(
                new PaymentProviderResource($provider), 
                __('payment_method.provider_updated')
            );
        } catch (Exception $e) {
            return ApiResponse::error($e->getMessage(), 400);
        }
    }
}
