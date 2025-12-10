<?php

namespace App\Http\Controllers\Api\V1\Borrower\Common;

use App\Http\Controllers\Controller;
use App\Services\Borrower\Common\BorrowerCommonService;
use Illuminate\Http\JsonResponse;

class BorrowerController extends Controller
{
    protected $borrowerCommonService;

    public function __construct(BorrowerCommonService $borrowerCommonService)
    {
        $this->borrowerCommonService = $borrowerCommonService;
    }

    public function getAdvisor(): JsonResponse
    {
        return $this->borrowerCommonService->getAdvisor();
    }
}
