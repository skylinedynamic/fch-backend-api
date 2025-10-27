<?php

namespace App\Http\Controllers\Api\V1\Global\TwoFaAuth;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Global\TwoFaAuth\SetupTwoFaRequest;
use App\Http\Requests\Api\V1\Global\TwoFaAuth\VerifyTwoFaRequest;
use App\Http\Requests\Api\V1\Global\TwoFaAuth\ResendTwoFaCodeRequest;
use App\Http\Resources\Global\TwoFaAuth\TwoFaSetupResource;
use App\Http\Resources\Global\TwoFaAuth\TwoFaStatusResource;
use App\Http\Resources\Global\TwoFaAuth\TwoFaVerificationResource;
use App\Services\Global\TwoFaAuth\TwoFaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TwoFaController extends Controller
{
    protected TwoFaService $service;

    public function __construct(TwoFaService $service)
    {
        $this->service = $service;
    }

    /**
     * 2FA setup 
     */
    public function setup(SetupTwoFaRequest $request): JsonResponse
    {
        $result = $this->service->setup($request->validated('method'));

        if($result['status'] === false){
            return ApiResponse::error($result['message'], 422);
        }
        
        return ApiResponse::success(new TwoFaSetupResource($result['data']), __('auth.two_factor_setup'));
    }

    /**
     * Verify 2FA code.
     */
    public function verify(VerifyTwoFaRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $result = $this->service->verify($validated['method'], $validated['code']);
        
        if ($result['status'] === true) {
            return ApiResponse::success(new TwoFaVerificationResource($result['data']), __('auth.2fa_verified'));
        }
        return ApiResponse::error(__('auth.2fa_verification_failed'), 422, new TwoFaVerificationResource($result));
    }

    /**
     * Disable 2FA.
     */
    public function disable(Request $request): JsonResponse
    {
        $user = auth()->user();
        $result = $this->service->disable($user);
        
        if($result === false){
            return ApiResponse::error(__('auth.2fa_disable_error'), 422);
        }
        return ApiResponse::success([], __('auth.2fa_disabled'));
    }

    /**
     * Resend code for SMS/Email.
     */
    public function resend(ResendTwoFaCodeRequest $request): JsonResponse
    {
        $user = $request->user();
        $method = $request->validated('method');
        $result = $this->service->resend($user, $method);
        
        if($result['status'] === false){
            return ApiResponse::error($result['message'], 422);
        }
        
        return ApiResponse::success($result['data'], $result['message']);
    }

    /**
     * Get 2FA status.
     */
    public function status(): JsonResponse
    {
        $user = auth()->user();
        $result = $this->service->status($user);
        return ApiResponse::success(new TwoFaStatusResource($result), '2FA status');
    }

    /**
     * Regenerate recovery codes.
     */
    public function regenerateRecoveryCodes(): JsonResponse
    {
        $user = auth()->user();
        $result = $this->service->regenerateRecoveryCodes($user);
        return ApiResponse::success($result, 'Recovery codes regenerated');
    }
}