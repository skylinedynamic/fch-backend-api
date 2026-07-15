<?php

namespace App\Http\Controllers\Api\V1\Borrower\CapitalAccess;

use App\Http\Controllers\Controller;
use App\Services\Borrower\CapitalAccess\CapitalAccessStrategyService;
use Illuminate\Http\JsonResponse;
use App\Http\Requests\Api\V1\Borrower\CapitalAccess\SubmitCapitalAccessStepRequest;

class CapitalAccessStrategyController extends Controller
{
    public function __construct(protected CapitalAccessStrategyService $service) {}

    /** 
     * List capital access strategies for borrower.
     */
    public function index(): JsonResponse
    {
        return $this->service->list();
    }

    /** 
     * Get a lightweight list of active strategies for selection.
     */
    public function listForSelection(): JsonResponse
    {
        return $this->service->listForSelection();
    }

    /**
     * Show details of a capital access strategy.
     */
    public function show(int $id): JsonResponse
    {
        return $this->service->show($id);
    }

    /**
     * Show overview of a capital access strategy.
     */
    public function showOverview(int $id): JsonResponse
    {
        return $this->service->showOverview($id);
    }

    /**
     * Show pre-flight checklist of a capital access strategy.
     */
    public function showChecklist(int $id): JsonResponse
    {
        return $this->service->showChecklist($id);
    }

    /**
     * Show roadmap (sorted card sequence + per-card odds) of a capital access strategy.
     */
    public function showRoadmap(int $id): JsonResponse
    {
        return $this->service->showRoadmap($id);
    }

    /**
     * Show detail for a single roadmap step (sequence).
     */
    public function showStep(int $id, int $sequenceId): JsonResponse
    {
        return $this->service->showStep($id, $sequenceId);
    }

    /**
     * Start/Initialize a capital access play status as running.
     */
    public function startPlay(int $id): JsonResponse
    {
        return $this->service->startPlay($id);
    }

    /**
     * Submit/Log application detail for a single roadmap step (sequence).
     */
    public function submitStep(SubmitCapitalAccessStepRequest $request, int $id, int $sequenceId): JsonResponse
    {
        $validated = $request->validated();

        return $this->service->submitStep($id, $sequenceId, $validated);
    }
}
