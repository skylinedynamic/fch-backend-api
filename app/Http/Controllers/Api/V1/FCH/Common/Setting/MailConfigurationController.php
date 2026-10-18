<?php

namespace App\Http\Controllers\Api\V1\FCH\Common\Setting;

use App\Exceptions\MailConfiguration\VerificationCodeNotSent;
use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\FCH\Common\Setting\SaveMailConfigurationRequest;
use App\Http\Requests\Api\V1\FCH\Common\Setting\VerifyMailConfigurationRequest;
use App\Http\Resources\FCH\Common\Setting\MailConfigurationResource;
use App\Models\Setting\MailConfiguration;
use App\Services\FCH\Common\Setting\EmailConfigurationService;
use App\Services\FCH\Common\Setting\MailConfigurationService;
use Illuminate\Http\JsonResponse;

/**
 * Settings > Email Configuration: the signed-in user's own partner's mail
 * providers. The route requires `update email configuration settings`.
 */
class MailConfigurationController extends Controller
{
    public function __construct(
        protected MailConfigurationService $service,
        protected EmailConfigurationService $resolver,
    ) {
    }

    public function index(): JsonResponse
    {
        $partnerId = $this->partnerId();
        $fallbackSending = $this->resolver->fallbackConfiguration();

        return ApiResponse::success([
            'configurations' => MailConfigurationResource::collection($this->service->forPartner($partnerId)),
            'providers' => $this->service->providerOptions(),
            'uses_own_configuration' => $this->resolver->usesOwnConfiguration($partnerId),
            'fallback' => [
                'is_configured' => MailConfiguration::fallback()->exists(),
                // Whether any fallback provider can send right now.
                'is_verified' => $fallbackSending !== null,
                // The address mail goes out from while a partner has none of its own.
                'from_address' => $fallbackSending?->from_address,
            ],
            'can_manage_fallback' => FallbackMailConfigurationController::canManage(auth()->user()),
        ], __('mail_config.retrieved'));
    }

    public function store(SaveMailConfigurationRequest $request): JsonResponse
    {
        $config = $this->service->save($this->partnerId(), $request->validated());

        return ApiResponse::success(new MailConfigurationResource($config), __('mail_config.saved'));
    }

    public function destroy(int $mailConfiguration): JsonResponse
    {
        $this->service->delete($this->find($mailConfiguration));

        return ApiResponse::success(null, __('mail_config.deleted'));
    }

    public function makeDefault(int $mailConfiguration): JsonResponse
    {
        $config = $this->service->makeDefault($this->find($mailConfiguration));

        return ApiResponse::success(new MailConfigurationResource($config), __('mail_config.default_set'));
    }

    public function sendCode(int $mailConfiguration): JsonResponse
    {
        $user = auth()->user();

        try {
            $this->service->sendCode($this->find($mailConfiguration), $user);
        } catch (VerificationCodeNotSent $e) {
            return ApiResponse::error($e->getMessage(), 422);
        }

        return ApiResponse::success(null, __('mail_config.code_sent', ['email' => $user->email]));
    }

    public function verify(VerifyMailConfigurationRequest $request, int $mailConfiguration): JsonResponse
    {
        $config = $this->service->verify($this->find($mailConfiguration), $request->validated('code'), auth()->user());

        return ApiResponse::success(new MailConfigurationResource($config), __('mail_config.verified'));
    }

    /**
     * One of this partner's configurations; anyone else's - and the fallback -
     * is not found.
     */
    private function find(int $id): MailConfiguration
    {
        return MailConfiguration::forPartner($this->partnerId())->findOrFail($id);
    }

    private function partnerId(): int
    {
        $partnerId = auth()->user()->partner_id;

        abort_if(!$partnerId, 403, __('mail_config.no_partner'));

        return (int) $partnerId;
    }
}
