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
use App\Traits\ResolvesConfigPartner;
use App\Services\FCH\Partner\PartnerConfigurationChangeRecorder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

/**
 * Settings > SMS Configuration. Always the signed-in user's own partner; the
 * route requires `update sms configuration settings`.
 */
class SmsConfigurationController extends Controller
{
    use ResolvesConfigPartner;

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
        $partnerId = $this->partnerId();
        $before = $this->snapshot($this->service->forPartner($partnerId));

        $configuration = $this->service->save($partnerId, $request->validated());

        $this->recordConfigChange(
            $request,
            PartnerConfigurationChangeRecorder::AREA_SMS,
            'updated the SMS configuration',
            PartnerConfigurationChangeRecorder::diff($before, $this->snapshot($configuration), [
                'is_enabled' => 'Enabled',
                'account_sid' => 'Account SID',
                'messaging_service_sid' => 'Messaging service SID',
            ])
        );

        return ApiResponse::success($this->payload($configuration), __('sms_config.saved'));
    }

    /** The configuration's fields, for the activity log's before/after comparison. */
    private function snapshot(?SmsConfiguration $configuration): array
    {
        if (!$configuration) {
            return [];
        }

        return [
            'country' => $configuration->country,
            'provider' => $configuration->provider,
            'is_enabled' => (bool) $configuration->is_enabled,
            'from_number' => $configuration->from_number,
        ] + ($configuration->credentials ?? []);
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

    /** The caller's partner, or the one the host names with `partner_id`. */
    private function partnerId(): int
    {
        $partnerId = $this->configPartnerId(request());

        abort_if(!$partnerId, 403, __('sms_config.no_partner'));

        return (int) $partnerId;
    }
}
