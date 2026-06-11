<?php

namespace App\Http\Controllers\Api\V1\Borrower\CapitalAccess;

use App\Http\Controllers\Controller;
use App\Services\Borrower\CapitalAccess\CapitalAccessStrategyService;
use Illuminate\Http\JsonResponse;

class CapitalAccessStrategyController extends Controller
{
    public function __construct(protected CapitalAccessStrategyService $service)
    {
    }

    /**
     * List capital access strategies for borrower.
     */
    public function index(): JsonResponse
    {
        return $this->service->list();
    }

    /**
     * Show details of a capital access strategy.
     */
    public function show(int $id): JsonResponse
    {
        return $this->service->show($id);
    }
}
