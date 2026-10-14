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
use Illuminate\Support\Facades\Auth;
use App\Http\Requests\Api\V1\Global\Auth\LoginTwoFaConfirmRequest;
use App\Http\Resources\Global\Auth\UserResource;
use App\Models\User\User;
use App\Http\Requests\Api\V1\Global\TwoFaAuth\RegenerateRecoveryCodesRequest;

class TwoFaController extends Controller
{
    protected TwoFaService $service;

    public function __construct(TwoFaService $service)
    {
        $this->service = $service;
    }

     /**
     * 2FA Confirm For Login 
     */

    public function login_confirm(LoginTwoFaConfirmRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $user = User::find($validated['user_id']);
        if (!$user) {
            return ApiResponse::error(__('auth.user_not_found'), 404);
        }

        $rcResult = $this->service->verifyRecoveryCodeForLogin($user, $validated['method'], $validated['code']);
        if ($rcResult['status'] === true && (($rcResult['data']['verified'] ?? false) === true)) {
            return $this->respondWithLoginSuccess($user, ['recovery_code_used' => true]);
        }

        $result = $this->service->verifyForLogin($user, $validated['method'], $validated['code']);
        if ($result['status'] === true && (($result['data']['verified'] ?? false) === true)) {
            return $this->respondWithLoginSuccess($user);
        }

        return ApiResponse::error($result['message'] ?? __('auth.2fa_verification_failed'), 422, $result['data'] ?? null);
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

        // The service answers status=true for a wrong code and a lockout too;
        // only data.verified says whether the code was accepted.
        if (($result['data']['verified'] ?? false) === true) {
            return ApiResponse::success(new TwoFaVerificationResource($result['data']), __('auth.2fa_verified'));
        }
        return ApiResponse::error($result['message'] ?? __('auth.2fa_verification_failed'), 422, new TwoFaVerificationResource($result['data'] ?? []));
    }

    /**
     * Disable 2FA.
     */
    public function disable(Request $request): JsonResponse
    {
        $user = Auth::user();
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
        $user = Auth::user();
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
        $user = Auth::user();
        $result = $this->service->status($user);
        return ApiResponse::success(new TwoFaStatusResource($result), __('auth.2fa_status'));
    }

    /**
     * Regenerate recovery codes.
     */
    public function regenerateRecoveryCodes(RegenerateRecoveryCodesRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $user = Auth::user();
        if (!$user) {
            return ApiResponse::error(__('auth.user_not_found'), 404);
        }

        // Recovery codes sign their holder in, so they are only ever issued to
        // the account asking. user_id is still accepted for existing callers,
        // but only when it is that same account.
        if (!empty($validated['user_id']) && (int) $validated['user_id'] !== (int) $user->id) {
            return ApiResponse::forbidden(__('auth.access_denied'));
        }

        $result = $this->service->regenerateRecoveryCodes($user);

        return ApiResponse::success([
            'recovery_codes' => $result['recovery_codes'] ?? [],
        ], __('auth.recovery_codes_regenerated'));
    }

    private function respondWithLoginSuccess(User $user, array $twoFactor = []): JsonResponse
    {
        $token = $user->createToken(__('auth.token_name'))->accessToken;
        $payload = [
            'token' => $token,
            'user' => new UserResource($user),
        ];
        return ApiResponse::success($payload, __('auth.logged_in'));
    }
}