<?php

namespace App\Http\Controllers\Api\V1\FCH\Global;

use Illuminate\Http\JsonResponse;
use App\Http\Controllers\Controller;
use App\Services\Global\UserDetailService;

class UserDetailController extends Controller
{
    protected UserDetailService $userDetailService;

    /**
     * UserDetailController constructor.
     *
     * @param UserDetailService $userDetailService
     */
    public function __construct(UserDetailService $userDetailService)
    {
        $this->userDetailService = $userDetailService;
    }

    /**
     * Handle the incoming request.
     *
     * @return JsonResponse
     */
    public function __invoke(): JsonResponse
    {
        return $this->userDetailService->getUserDetails();
    }
}
