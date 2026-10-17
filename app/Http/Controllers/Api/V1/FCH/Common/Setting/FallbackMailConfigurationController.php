<?php

namespace App\Http\Controllers\Api\V1\FCH\Common\Setting;

use App\Exceptions\MailConfiguration\VerificationCodeNotSent;
use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\FCH\Common\Setting\SaveMailConfigurationRequest;
use App\Http\Requests\Api\V1\FCH\Common\Setting\VerifyMailConfigurationRequest;
use App\Http\Resources\FCH\Common\Setting\MailConfigurationResource;
use App\Models\Partner\Partner;
use App\Models\Setting\MailConfiguration;
use App\Models\User\User;
use App\Services\FCH\Common\Setting\MailConfigurationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

/**
 * Settings > Email Configuration > Fallback: the one mail configuration every
 * partner without a verified default sends through. Only the host partner's
 * own account (entity_type Partner) - not its employees, nor any other
 * partner - may see or change it.
 */
class FallbackMailConfigurationController extends Controller implements HasMiddleware
{
    public function __construct(
        protected MailConfigurationService $service
    ) {
    }

    /**
     * Checked before anything else - a non-host user gets 403 whatever it
     * sends, rather than validation messages about the form.
     */
    public static function middleware(): array
    {
        return [
            new Middleware(function (Request $request, \Closure $next) {
                abort_unless(self::canManage($request->user()), 403, __('mail_config.fallback_forbidden'));

                return $next($request);
            }),
        ];
    }

    public static function canManage(?User $user): bool
    {
        return $user !== null
            && $user->entity_type === Partner::class
            && (bool) $user->partner?->is_host;
    }

    public function index(): JsonResponse
    {
        return ApiResponse::success($this->payload($this->service->fallback()), __('mail_config.retrieved'));
    }

    public function store(SaveMailConfigurationRequest $request): JsonResponse
    {
        $config = $this->service->save(null, $request->validated());

        return ApiResponse::success($this->payload($config), __('mail_config.saved'));
    }

    public function sendCode(): JsonResponse
    {
        $user = auth()->user();

        try {
            $this->service->sendCode($this->fallbackOrFail(), $user);
        } catch (VerificationCodeNotSent $e) {
            return ApiResponse::error($e->getMessage(), 422);
        }

        return ApiResponse::success(null, __('mail_config.code_sent', ['email' => $user->email]));
    }

    public function verify(VerifyMailConfigurationRequest $request): JsonResponse
    {
        $config = $this->service->verify($this->fallbackOrFail(), $request->validated('code'), auth()->user());

        return ApiResponse::success($this->payload($config), __('mail_config.verified'));
    }

    private function payload(?MailConfiguration $config): array
    {
        return [
            'configuration' => $config ? new MailConfigurationResource($config) : null,
            'providers' => $this->service->providerOptions(),
        ];
    }

    private function fallbackOrFail(): MailConfiguration
    {
        $fallback = $this->service->fallback();

        abort_if(!$fallback, 404, __('mail_config.not_complete'));

        return $fallback;
    }
}
