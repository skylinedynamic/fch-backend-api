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
use App\Traits\ResolvesConfigPartner;
use App\Services\FCH\Partner\PartnerConfigurationChangeRecorder;
use Illuminate\Http\JsonResponse;

/**
 * Settings > Email Configuration: the signed-in user's own partner's mail
 * providers. The route requires `update email configuration settings`.
 */
class MailConfigurationController extends Controller
{
    use ResolvesConfigPartner;

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
            // The host's own fallback mailbox is not part of another partner's
            // settings (Partner profile › Configuration).
            'can_manage_fallback' => !$this->namesConfigPartner(request())
                && FallbackMailConfigurationController::canManage(auth()->user()),
        ], __('mail_config.retrieved'));
    }

    public function store(SaveMailConfigurationRequest $request): JsonResponse
    {
        $partnerId = $this->partnerId();
        $provider = (string) $request->validated('provider');
        $before = MailConfiguration::forPartner($partnerId)->where('provider', $provider)->first();

        $config = $this->service->save($partnerId, $request->validated());

        $fields = MailConfiguration::providers()[$provider]['fields'] ?? [];
        $this->recordConfigChange(
            $request,
            PartnerConfigurationChangeRecorder::AREA_EMAIL,
            "saved the {$this->providerLabel($provider)} mail provider",
            PartnerConfigurationChangeRecorder::diff(
                $this->snapshot($before),
                $this->snapshot($config),
                ['from_address' => 'From address'] + array_map(fn (array $field) => $field['label'] ?? null, $fields),
                array_keys(array_filter($fields, fn (array $field) => !empty($field['secret'])))
            )
        );

        return ApiResponse::success(new MailConfigurationResource($config), __('mail_config.saved'));
    }

    public function destroy(int $mailConfiguration): JsonResponse
    {
        $config = $this->find($mailConfiguration);
        $this->service->delete($config);
        $this->recordConfigChange(request(), PartnerConfigurationChangeRecorder::AREA_EMAIL, "removed the {$this->providerLabel($config->provider)} mail provider");

        return ApiResponse::success(null, __('mail_config.deleted'));
    }

    public function makeDefault(int $mailConfiguration): JsonResponse
    {
        $config = $this->service->makeDefault($this->find($mailConfiguration));
        $this->recordConfigChange(request(), PartnerConfigurationChangeRecorder::AREA_EMAIL, "made {$this->providerLabel($config->provider)} the default mail provider");

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
        $this->recordConfigChange($request, PartnerConfigurationChangeRecorder::AREA_EMAIL, "verified the {$this->providerLabel($config->provider)} mail provider");

        return ApiResponse::success(new MailConfigurationResource($config), __('mail_config.verified'));
    }

    /** The provider's name as the settings screen shows it (SMTP, Amazon SES). */
    private function providerLabel(?string $provider): string
    {
        return MailConfiguration::providers()[$provider]['label'] ?? strtoupper((string) $provider);
    }

    /** The configuration's fields, for the activity log's before/after comparison. */
    private function snapshot(?MailConfiguration $config): array
    {
        return $config ? ['from_address' => $config->from_address] + ($config->credentials ?? []) : [];
    }

    /**
     * One of this partner's configurations; anyone else's - and the fallback -
     * is not found.
     */
    private function find(int $id): MailConfiguration
    {
        return MailConfiguration::forPartner($this->partnerId())->findOrFail($id);
    }

    /** The caller's partner, or the one the host names with `partner_id`. */
    private function partnerId(): int
    {
        $partnerId = $this->configPartnerId(request());

        abort_if(!$partnerId, 403, __('mail_config.no_partner'));

        return (int) $partnerId;
    }
}
