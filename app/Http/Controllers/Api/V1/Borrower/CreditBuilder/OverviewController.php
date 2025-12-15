<?php

namespace App\Http\Controllers\Api\V1\Borrower\CreditBuilder;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Resources\Borrower\CreditBuilder\MyProgressResource;
use App\Services\Borrower\CreditBuilder\OverviewService;
use Illuminate\Http\Request;

class OverviewController extends Controller
{
    protected $overviewService;

    public function __construct(OverviewService $overviewService)
    {
        $this->overviewService = $overviewService;
    }

    /**
     * Get My Progress
     */
    public function index()
    {
        $data = $this->overviewService->myProgress();

        return ApiResponse::success(
            new MyProgressResource($data),
            __('borrower.credit_builder_progress_retrieved'),
        );
    }
}
