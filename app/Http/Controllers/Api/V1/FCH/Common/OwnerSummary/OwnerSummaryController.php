<?php

namespace App\Http\Controllers\Api\V1\FCH\Common\OwnerSummary;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\FCH\Common\OwnerSummary\ExportOwnerSummary;
use App\Http\Requests\Api\V1\FCH\Common\OwnerSummary\FetchOwnerSummary;
use App\Services\FCH\Common\OwnerSummary\OwnerSummaryExportPdf;
use App\Services\FCH\Common\OwnerSummary\OwnerSummaryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

/**
 * GET /api/v1/common/owner-summary - the host owner's business summary for a
 * period (see OwnerSummaryService for what each number means), and
 * GET /api/v1/common/owner-summary/export - the same as a PDF report.
 */
class OwnerSummaryController extends Controller
{
    public function __construct(private readonly OwnerSummaryService $service)
    {
    }

    public function show(FetchOwnerSummary $request): JsonResponse
    {
        return ApiResponse::success(
            $this->service->summary($request->user(), $request->summaryPeriod(), $request->environment()),
            __('owner_summary.retrieved')
        );
    }

    /** Business Insights › Export PDF: the summary or the details report, as a download. */
    public function export(ExportOwnerSummary $request, OwnerSummaryExportPdf $pdf): Response
    {
        $report = $pdf->report($request->user(), $request->type(), $request->summaryPeriod(), $request->environment());

        return response($pdf->render($request->type(), $report), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename=' . $pdf->filename($request->type(), $report),
        ]);
    }
}
