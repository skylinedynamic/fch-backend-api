<?php

namespace App\Http\Controllers\Api\V1\FCH\Common\Setting;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\FCH\Common\Setting\SendTestSmsRequest;
use App\Http\Requests\Api\V1\FCH\Common\Setting\StoreSmsConfigurationRequest;
use App\Http\Resources\FCH\Common\Setting\SmsConfigurationResource;
use App\Models\Setting\SmsConfiguration;
use App\Services\FCH\Common\Notification\Sms\SmsDeliveryException;
use App\Services\FCH\Common\Setting\SmsConfigurationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

/**
 * Settings > SMS Configuration. Always the signed-in user's own partner; the
 * route requires `update sms configuration settings`.
 */
class SmsConfigurationController extends Controller
{
    public function __construct(
        protected SmsConfigurationService $service
    ) {
    }

    public function index(): JsonResponse
    {
        return ApiResponse::success(
            $this->payload($this->service->forPartner($this->partnerId())),
            __('sms_config.retrieved')
        );
    }

    public function store(StoreSmsConfigurationRequest $request): JsonResponse
    {
        $configuration = $this->service->save($this->partnerId(), $request->validated());

        return ApiResponse::success($this->payload($configuration), __('sms_config.saved'));
    }

    public function test(SendTestSmsRequest $request): JsonResponse
    {
        $partnerId = $this->partnerId();

        try {
            $this->service->sendTest($partnerId, $request->validated('phone'));
        } catch (SmsDeliveryException $e) {
            Log::warning(__('sms_config.logs.test_failed'), [
                'partner_id' => $partnerId,
                'reason' => $e->getMessage(),
            ]);

            return ApiResponse::error(__('sms_config.test_failed', ['reason' => $e->getMessage()]), 422);
        }

        return ApiResponse::success(null, __('sms_config.test_sent'));
    }

    private function payload(?SmsConfiguration $configuration): array
    {
        return [
            'configuration' => $configuration ? new SmsConfigurationResource($configuration) : null,
            'options' => $this->service->options(),
        ];
    }

    private function partnerId(): int
    {
        $partnerId = auth()->user()->partner_id;

        abort_if(!$partnerId, 403, __('sms_config.no_partner'));

        return (int) $partnerId;
    }
}
