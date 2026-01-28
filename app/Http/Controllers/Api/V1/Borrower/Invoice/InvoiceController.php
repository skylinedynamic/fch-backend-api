<?php

namespace App\Http\Controllers\Api\V1\Borrower\Invoice;

use App\Http\Controllers\Controller;
use App\Models\Invoice\Invoice;
use App\Services\Borrower\Invoice\InvoiceService;
use App\Helpers\ApiResponse;
use App\Http\Resources\Borrower\Invoice\InvoiceResource;
use Illuminate\Http\Request;

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
}
