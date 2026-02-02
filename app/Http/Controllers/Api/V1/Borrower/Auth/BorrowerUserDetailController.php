<?php

namespace App\Http\Controllers\Api\V1\Borrower\Auth;

use Illuminate\Http\JsonResponse;
use App\Http\Controllers\Controller;
use App\Services\Borrower\BorrowerUserDetailService;

class BorrowerUserDetailController extends Controller
{
    protected BorrowerUserDetailService $borrowerUserDetailService;

    /**
     * BorrowerUserDetailController constructor.
     *
     * @param BorrowerUserDetailService $borrowerUserDetailService
     */
    public function __construct(BorrowerUserDetailService $borrowerUserDetailService)
    {
        $this->borrowerUserDetailService = $borrowerUserDetailService;
    }

    /**
     * Handle the incoming request.
     *
     * @return JsonResponse
     */
    public function __invoke(): JsonResponse
    {
        return $this->borrowerUserDetailService->getUserDetails();
    }
}
