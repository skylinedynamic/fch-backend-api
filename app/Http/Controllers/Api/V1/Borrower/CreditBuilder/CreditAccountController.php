<?php

namespace App\Http\Controllers\Api\V1\Borrower\CreditBuilder;

use App\Http\Controllers\Controller;
use App\Services\Borrower\CreditBuilder\CreditAccountService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CreditAccountController extends Controller
{
    public function __construct(protected CreditAccountService $creditAccountService)
    {
    }

    /**
     * List credit accounts for borrower.
     */
    public function index(Request $request): JsonResponse
    {
        return $this->creditAccountService->list($request->all());
    }

    /**
     * Apply/Update status for a credit account.
     */
    public function apply(Request $request, int $id): JsonResponse
    {
        return $this->creditAccountService->apply($id, $request->status);
    }

    /**
     * Get filter options for credit accounts.
     */
    public function filterOptions(): JsonResponse
    {
        return $this->creditAccountService->getFilterOptions();
    }
}
