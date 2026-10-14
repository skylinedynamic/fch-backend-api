<?php

namespace App\Http\Controllers\Api\V1\Borrower\Billing;

use App\Http\Controllers\Controller;
use App\Services\Borrower\Billing\BorrowerBillingService;
use Illuminate\Http\JsonResponse;

use Illuminate\Http\Request;

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

    public function getPaymentProviders(): JsonResponse
    {
        return $this->borrowerBillingService->getPaymentProviders();
    }

    public function updateDefaultPaymentMethod(Request $request): JsonResponse
    {
        return $this->borrowerBillingService->updateDefaultPaymentMethod($request->all());
    }

    public function removeDefaultPaymentMethod(): JsonResponse
    {
        return $this->borrowerBillingService->removeDefaultPaymentMethod();
    }

    public function cancelSubscription(Request $request): JsonResponse
    {
        return $this->borrowerBillingService->cancelSubscription($request->all());
    }
}
