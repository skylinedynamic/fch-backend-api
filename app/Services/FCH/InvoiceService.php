<?php

namespace App\Services\FCH;

use App\Models\Invoice\Invoice;
use App\Traits\PaginatesApi;
use App\Http\Resources\Api\V1\FCH\InvoiceResource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Illuminate\Http\JsonResponse;

class InvoiceService
{
    use PaginatesApi;
    /**
     * Get list of invoices based on filters.
     *
     * @param array $filters
     * @return JsonResponse
     */
    public function getInvoices(array $filters): JsonResponse
    {
        $query = Invoice::query();

        // Filter by Date Range (Payment Date)
        // if (!empty($filters['date_from'])) {
        //     $query->where('payment_date', '>=', $filters['date_from']);
        // }
        // if (!empty($filters['date_to'])) {
        //     $query->where('payment_date', '<=', $filters['date_to']);
        // }

        // // Filter by Status
        // if (!empty($filters['status']) && $filters['status'] !== Invoice::STATUS_ALL) {
        //     // Handle "All Status" if passed from frontend, though usually frontend sends specific value or null
        //     $query->where('status', $filters['status']);
        // }

        // // Filter by Cycle
        // if (!empty($filters['cycle']) && $filters['cycle'] !== Invoice::CYCLE_ALL) {
        //     $query->where('cycle', $filters['cycle']);
        // }

        // // Filter by Payees (Customer Name or Partner ID) - assuming 'payee' is passed. 
        // // If 'All Payees' is passed, we ignore.
        // // For now, let's allow searching/filtering by specific payee if needed.
        // if (!empty($filters['payee']) && $filters['payee'] !== Invoice::PAYEE_ALL) {
        //     $query->where('customer_name', 'like', '%' . $filters['payee'] . '%');
        // }

        // // General Search (optional)
        // if (!empty($filters['search'])) {
        //     $search = $filters['search'];
        //     $query->where(function (Builder $q) use ($search) {
        //         $q->where('customer_name', 'like', "%{$search}%")
        //             ->orWhere('email', 'like', "%{$search}%")
        //             ->orWhere('invoice_id', 'like', "%{$search}%");
        //     });
        // }

        // Sorting
        $query->orderBy('created_at', 'desc');

        $perPage = isset($filters['per_page']) ? max(1, (int) $filters['per_page']) : 10;
        $page = isset($filters['page']) ? max(1, (int) $filters['page']) : 1;

        $paginator = $this->paginateBuilderWith($query, $perPage, $page);

        return $this->respondWithPaginationResource(
            $paginator,
            InvoiceResource::class,
            __('invoice.list_retrieved')
        );
    }

    /**
     * Get invoice statistics.
     *
     * @param array $filters
     * @return array
     */
    public function getStats(array $filters): array
    {
        $query = Invoice::query();

        if (!empty($filters['date_from'])) {
            $query->where('payment_date', '>=', $filters['date_from']);
        }
        if (!empty($filters['date_to'])) {
            $query->where('payment_date', '<=', $filters['date_to']);
        }
        if (!empty($filters['cycle']) && $filters['cycle'] !== Invoice::CYCLE_ALL) {
            $query->where('cycle', $filters['cycle']);
        }
        if (!empty($filters['payee']) && $filters['payee'] !== Invoice::PAYEE_ALL) {
            $query->where('customer_name', 'like', '%' . $filters['payee'] . '%');
        }

        $invoices = $query->get(['payment_amount', 'status']);

        $totalInvoicesSentAmount = $invoices->sum('payment_amount');

        $paidInvoices = $invoices->whereIn('status', [Invoice::STATUS_PAID, Invoice::STATUS_SUCCESS]);
        $paidAmount = $paidInvoices->sum('payment_amount');

        $unpaidInvoices = $invoices->whereIn('status', [Invoice::STATUS_UNPAID, Invoice::STATUS_PENDING, Invoice::STATUS_FAILED, Invoice::STATUS_DISPUTED]);
        $unpaidAmount = $unpaidInvoices->sum('payment_amount');

        $collectionRate = $totalInvoicesSentAmount > 0 ? ($paidAmount / $totalInvoicesSentAmount) * 100 : 0;

        return [
            'invoices_sent' => $totalInvoicesSentAmount,
            'paid_invoices' => $paidAmount,
            'unpaid_invoices' => $unpaidAmount,
            'collection_rate' => round($collectionRate, 2)
        ];
    }

    /**
     * Generate a unique Invoice ID (INV-xxxx).
     *
     * @return string
     */
    public function generateInvoiceId(): string
    {
        $lastInvoice = Invoice::latest('id')->first();

        if (!$lastInvoice || !$lastInvoice->invoice_id) {
            return 'INV-1001';
        }

        // Extract number from INV-xxxx
        $lastIdNumber = (int) Str::after($lastInvoice->invoice_id, 'INV-');
        $newIdNumber = $lastIdNumber + 1;

        return 'INV-' . $newIdNumber;
    }

    /**
     * Find Invoice by ID.
     * 
     * @param string|int $id
     * @return Invoice|null
     */
    public function find($id)
    {
        return Invoice::find($id) ?? Invoice::where('invoice_id', $id)->first();
    }
}
