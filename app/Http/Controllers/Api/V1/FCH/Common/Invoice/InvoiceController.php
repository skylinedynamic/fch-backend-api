<?php

namespace App\Http\Controllers\Api\V1\FCH\Common\Invoice;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\FCH\InvoiceResource;
use App\Services\FCH\InvoiceService;
use App\Http\Requests\Api\V1\FCH\Common\Invoice\FetchInvoiceRequest;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class InvoiceController extends Controller
{
    protected $invoiceService;

    public function __construct(InvoiceService $invoiceService)
    {
        $this->invoiceService = $invoiceService;
    }

    /**
     * Get list of invoices.
     *
     * @param FetchInvoiceRequest $request
     * @return JsonResponse
     */
    public function index(FetchInvoiceRequest $request): JsonResponse
    {
        $filters = $request->validated();

        return $this->invoiceService->getInvoices($filters);
    }

    /**
     * Get invoice statistics.
     *
     * @param FetchInvoiceRequest $request
     * @return JsonResponse
     */
    public function stats(FetchInvoiceRequest $request): JsonResponse
    {
        $filters = $request->validated();
        $stats = $this->invoiceService->getStats($filters);

        return ApiResponse::success($stats, __('invoice.stats_retrieved'));
    }

    /**
     * Get single invoice details.
     *
     * @param string|int $id
     * @return JsonResponse
     */
    public function show($id): JsonResponse
    {
        $invoice = $this->invoiceService->find($id);

        if (!$invoice) {
            return ApiResponse::notFound(__('invoice.not_found'));
        }

        return ApiResponse::success(new InvoiceResource($invoice), __('invoice.details_retrieved'));
    }
}
