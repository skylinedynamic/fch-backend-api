<?php

namespace App\Http\Controllers\Api\V1\FCH\Common\Invoice;

use App\Helpers\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Http\Controllers\Controller;
use App\Services\FCH\Common\Invoice\InvoiceService;
use App\Http\Resources\FCH\Invoice\InvoiceResource;
use App\Http\Resources\FCH\Invoice\InvoiceDetailResource;
use App\Http\Requests\Api\V1\FCH\Common\Invoice\FetchInvoiceRequest;

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

        return ApiResponse::success(new InvoiceDetailResource($invoice), __('invoice.details_retrieved'));
    }

    /**
     * Download invoice PDF.
     *
     * @param string|int $id
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

    /**
     * Send invoice email.
     *
     * @param string|int $id
     * @return JsonResponse
     */
    public function send($id): JsonResponse
    {
        try {
            $this->invoiceService->sendInvoice($id);
            return ApiResponse::success(null, __('invoice.sent_success'));
        } catch (\Exception $e) {
            return ApiResponse::serverError($e->getMessage());
        }
    }
}
