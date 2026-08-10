<?php

namespace App\Http\Controllers\Api\V1\Borrower\Billing;

use App\Http\Controllers\Controller;
use App\Services\Borrower\Billing\BorrowerBillingService;
use Illuminate\Http\JsonResponse;

class BillingController extends Controller
{
    protected $borrowerBillingService;

    public function __construct(BorrowerBillingService $borrowerBillingService)
    {
        $this->borrowerBillingService = $borrowerBillingService;
    }

    public function getBillingDetails(): JsonResponse
    {
        return $this->borrowerBillingService->getBillingDetails();
    }
}
