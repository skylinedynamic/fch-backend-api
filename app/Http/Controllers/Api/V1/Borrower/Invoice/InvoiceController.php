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
use App\Http\Requests\Api\V1\FCH\Common\Invoice\FetchInvoiceRequest;

class InvoiceController extends Controller
{
    protected InvoiceService $invoiceService;

    public function __construct(InvoiceService $invoiceService)
    {
        $this->invoiceService = $invoiceService;
    }

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $borrowerId = auth()->user()->entity_id;

        $perPage = $request->input('per_page', 10);
        $search = $request->input('search');

        $invoices = Invoice::where('borrower_id', $borrowerId)
            ->where('partner_id', auth()->user()->partner_id)
            ->where('is_partner_paying', false)
            ->with(['partner']) // Eager load partner if needed
            ->search($search)
            ->latest()
            ->paginate($perPage);

        return InvoiceResource::collection($invoices);
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
}
