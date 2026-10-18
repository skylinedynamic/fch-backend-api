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
use App\Services\FCH\Common\Setting\EmailConfigurationService;
use App\Services\FCH\Common\Setting\MailConfigurationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

/**
 * Settings > Email Configuration > Fallback: the mail configuration every
 * partner without a verified default sends through - one per provider, sent
 * with its verified default, exactly like a partner's own. Only the host partner's
 * own account (entity_type Partner) - not its employees, nor any other
 * partner - may see or change it.
 */
class FallbackMailConfigurationController extends Controller implements HasMiddleware
{
    public function __construct(
        protected MailConfigurationService $service,
        protected EmailConfigurationService $resolver,
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
        return ApiResponse::success([
            'configurations' => MailConfigurationResource::collection($this->service->fallbacks()),
            'providers' => $this->service->providerOptions(),
            // The one mail goes out with now: the verified default, else
            // another verified provider; null when none can send.
            'sending_id' => $this->resolver->fallbackConfiguration()?->id,
        ], __('mail_config.retrieved'));
    }

    public function store(SaveMailConfigurationRequest $request): JsonResponse
    {
        $config = $this->service->save(null, $request->validated());

        return ApiResponse::success(new MailConfigurationResource($config), __('mail_config.saved'));
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

    /** One of the fallback's configurations; a partner's own is not found. */
    private function find(int $id): MailConfiguration
    {
        return MailConfiguration::fallback()->findOrFail($id);
    }
}
