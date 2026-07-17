<?php

namespace App\Http\Controllers\Api\V1\Borrower\Invoice;

use App\Models\Invoice\Invoice;
use App\Helpers\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use App\Http\Controllers\Controller;
use App\Services\Borrower\Invoice\InvoiceService;
use App\Http\Resources\Borrower\Invoice\InvoiceResource;
use App\Http\Resources\Borrower\Invoice\InvoiceDetailResource;
use App\Http\Requests\Api\V1\FCH\Common\Invoice\FetchInvoiceRequest;

class InvoiceController extends Controller
{
    protected InvoiceService $invoiceService;

    public function __construct(InvoiceService $invoiceService)
    {
        $this->invoiceService = $invoiceService;
    }

    /**
     * Display a listing of the resource with comprehensive filtering.
     * 
     * Supports:
     * - Search across: Invoice ID, Customer Name, Email, Amount, Date, Status, Partner, Borrower
     * - Status dropdown filtering (e.g., "Paid", "Unpaid", "All Status")
     * - Date range filtering (date_from, date_to)
     * - Advanced column filtering with "contains" matching
     * - Sorting by any column
     */
    public function index(Request $request)
    {
        $filters = [
            'search' => $request->input('search'),
            'status' => $request->input('status'),
            'date_from' => $request->input('date_from'),
            'date_to' => $request->input('date_to'),
            'per_page' => $request->input('per_page', 10),
            'page' => $request->input('page', 1),
            'sort_field' => $request->input('sort_field', 'id'),
            'sort_order' => $request->input('sort_order', 'desc'),
            'filters' => $request->input('filters', []), // Advanced column filters
        ];

        return $this->invoiceService->list($filters);
    }


    public function checkUnpaidInvoice()
    {
        try {
            $borrowerId = auth()->user()->entity_id;
            $unpaidInvoices = $this->invoiceService->getUnpaidInvoices($borrowerId);

            return ApiResponse::success(InvoiceResource::collection($unpaidInvoices), __('borrower.unpaid_invoices_fetched_successfully'));
        } catch (\Throwable $e) {
            return ApiResponse::error($e->getMessage());
        }
    }

    /**
     * Get invoice statistics for borrower.
     *
     * @param FetchInvoiceRequest $request
     * @return JsonResponse
     */
    public function stats(FetchInvoiceRequest $request): JsonResponse
    {
        try {
            $user = Auth::user();
            $borrowerId = $user->entity_id;
            $partnerId = $user->partner_id ?? 0;
            $filters = $request->validated();

            $stats = $this->invoiceService->getStats((int)$borrowerId, (int)$partnerId, $filters);

            return ApiResponse::success($stats, __('invoice.stats_retrieved'));
        } catch (\Throwable $e) {
            return ApiResponse::error($e->getMessage());
        }
    }

    /**
     * Display the specified invoice detail for the borrower.
     *
     * @param int|string $id
     * @return JsonResponse
     */
    public function show($id): JsonResponse
    {
        try {
            $invoice = $this->invoiceService->find($id);

            if (!$invoice) {
                return ApiResponse::notFound(__('invoice.not_found'));
            }

            return ApiResponse::success(new InvoiceDetailResource($invoice), __('invoice.details_retrieved'));
        } catch (\Throwable $e) {
            return ApiResponse::error($e->getMessage());
        }
    }

    /**
     * Download Invoice PDF for the borrower.
     *
     * @param int|string $id
     * @return mixed
     */
    public function download($id)
    {
        try {
            $pdf = $this->invoiceService->downloadInvoice($id);
            $base64 = base64_encode($pdf->output());
            return ApiResponse::success([
                'file' => $base64,
                'mime_type' => 'application/pdf',
                'file_name' => "Invoice-{$id}.pdf"
            ], __('invoice.downloaded_successfully'));
        } catch (\Exception $e) {
            return ApiResponse::notFound($e->getMessage());
        }
    }
}
