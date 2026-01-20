<?php

namespace App\Services\FCH;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use App\Models\Invoice\Invoice;
use App\Traits\PaginatesApi;
use App\Http\Resources\Api\V1\FCH\InvoiceResource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Mail;
use App\Mail\Invoice\InvoiceMail;

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
        if (!empty($filters['date_from'])) {
            $query->whereDate('payment_date', '>=', $filters['date_from']);
        }
        if (!empty($filters['date_to'])) {
            $query->whereDate('payment_date', '<=', $filters['date_to']);
        }

        if (!empty($filters['date_range']) && is_array($filters['date_range'])) {
            if (isset($filters['date_range'][0]) && isset($filters['date_range'][1])) {
                $query->whereBetween('payment_date', [$filters['date_range'][0], $filters['date_range'][1]]);
            }
        }

        // Filter by Status
        if (!empty($filters['status']) && $filters['status'] !== 'All Status') {
            $query->where('status', $filters['status']);
        }

        // Filter by Cycle
        if (!empty($filters['cycle']) && $filters['cycle'] !== 'All Cycle') {
            $query->where('cycle', $filters['cycle']);
        }

        // Filter by Payee
        if (!empty($filters['payee_id'])) {
            $query->where('entity_id', $filters['payee_id']);
        }

        // General Search
        if (!empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function (Builder $q) use ($search) {
                $q->where('customer_name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('invoice_id', 'like', "%{$search}%");
            });
        }

        // Sorting
        $sortField = $filters['sort_field'] ?? 'created_at';
        $sortOrder = $filters['sort_order'] ?? 'desc';
        $query->orderBy($sortField, $sortOrder);

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
            $query->whereDate('payment_date', '>=', $filters['date_from']);
        }
        if (!empty($filters['date_to'])) {
            $query->whereDate('payment_date', '<=', $filters['date_to']);
        }
        if (!empty($filters['cycle']) && $filters['cycle'] !== Invoice::CYCLE_ALL) {
            $query->where('cycle', $filters['cycle']);
        }
        if (!empty($filters['payee']) && $filters['payee'] !== Invoice::PAYEE_ALL) {
            $query->where('customer_name', 'like', '%' . $filters['payee'] . '%');
        }

        $allInvoices = (clone $query)->get(['payment_amount', 'status']);

        $totalSent = $allInvoices->sum('payment_amount');

        $paidAmount = $allInvoices->whereIn('status', [Invoice::STATUS_PAID, Invoice::STATUS_SUCCESS])
            ->sum('payment_amount');

        $unpaidAmount = $allInvoices->whereIn('status', [Invoice::STATUS_UNPAID, Invoice::STATUS_PENDING, Invoice::STATUS_OVERDUE])
            ->sum('payment_amount');

        $collectionRate = $totalSent > 0 ? ($paidAmount / $totalSent) * 100 : 0;

        return [
            'invoices_sent' => $totalSent,
            'paid_invoices' => $paidAmount,
            'unpaid_invoices' => $unpaidAmount,
            'collection_rate' => round($collectionRate, 2)
        ];
    }

    /**
     * Generate a unique Invoice ID (INV-YYYYMMDDXXX).
     *
     * @return string
     */
    public function generateInvoiceId(): string
    {
        $today = now()->format('Ymd');

        // Count invoices created today to determine the sequence
        $todayCount = Invoice::where('invoice_id', 'like', "INV-{$today}%")->count();
        $sequence = str_pad($todayCount + 1, 3, '0', STR_PAD_LEFT);

        return "INV-{$today}{$sequence}";
    }

    /**
     * Create a new invoice.
     *
     * @param array $data
     * @return Invoice
     */
    public function create(array $data): Invoice
    {
        if (empty($data['invoice_id'])) {
            $data['invoice_id'] = $this->generateInvoiceId();
        }

        $data['created_by'] = Auth::id();
        $data['updated_by'] = Auth::id();

        return Invoice::create($data);
    }

    public function find($id)
    {
        return Invoice::find($id) ?? Invoice::where('invoice_id', $id)->first();
    }

    /**
     * Download Invoice PDF.
     */
    public function downloadInvoice($id)
    {
        $invoice = $this->find($id);
        if (!$invoice) throw new \Exception("Invoice not found");

        // We use a simple approach for now. Real PDF would use Barryvdh\DomPDF
        // For accuracy in this environment, we just ensure data is ready.
        return $invoice;
    }

    /**
     * Send Invoice Email.
     */
    public function sendInvoice($id)
    {
        $invoice = $this->find($id);
        if (!$invoice) throw new \Exception("Invoice not found");

        Mail::to($invoice->email)->send(new InvoiceMail($invoice));
        return true;
    }
}
