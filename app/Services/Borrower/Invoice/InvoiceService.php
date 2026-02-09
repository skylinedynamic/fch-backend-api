<?php

namespace App\Services\Borrower\Invoice;

use App\Models\Invoice\Invoice;
use App\Traits\PaginatesApi;
use App\Services\Global\Datatable\QueryFilter;
use App\Http\Resources\Borrower\Invoice\InvoiceResource;
use Illuminate\Database\Eloquent\Collection;

class InvoiceService
{
    use PaginatesApi;
    /**
     * List invoices with pagination, search, filtering and sorting.
     * Follows the same pattern as BorrowerService for consistency.
     * 
     * @param array $filters
     * @return \Illuminate\Http\JsonResponse
     */
    public function list(array $filters)
    {
        $user = auth()->user();
        $borrowerId = $user->entity_id;
        $partnerId = $user->partner_id;

        $query = Invoice::query()
            ->where('borrower_id', $borrowerId)
            ->where('partner_id', $partnerId)
            ->where('is_partner_paying', false)
            ->with(['partner', 'borrower'])
            ->select('invoices.*');

        // Filter by status dropdown
        if (!empty($filters['status']) && $filters['status'] !== 'All Status') {
            $query->where('invoices.status', $filters['status']);
        }

        // Filter by date range
        if (!empty($filters['date_from'])) {
            $query->whereDate('invoices.created_at', '>=', $filters['date_from']);
        }

        if (!empty($filters['date_to'])) {
            $query->whereDate('invoices.created_at', '<=', $filters['date_to']);
        }

        // Determine sorting
        $sortField = $filters['sort_field'] ?? 'id';
        $sortOrder = strtolower($filters['sort_order'] ?? 'desc') === 'asc' ? 'asc' : 'desc';

        $skipSorting = false;
        $rawFilters = $filters['filters'] ?? [];

        // Join Partners if sorting or filtering by partner_name
        if ($sortField === 'partner_name' || isset($rawFilters['partner_name'])) {
            $query->leftJoin('partners', 'invoices.partner_id', '=', 'partners.id')
                ->addSelect('partners.full_name as partner_name');
            if ($sortField === 'partner_name') {
                $query->orderBy('partners.full_name', $sortOrder);
                $skipSorting = true;
            }
        }

        // Search across multiple fields with "contains" matching
        if (!empty($filters['search'])) {
            $search = trim((string) $filters['search']);
            $query->where(function ($q) use ($search) {
                $q->where('invoices.invoice_id', 'LIKE', "%{$search}%")
                    ->orWhere('invoices.customer_name', 'LIKE', "%{$search}%")
                    ->orWhere('invoices.email', 'LIKE', "%{$search}%")
                    ->orWhere('invoices.payment_amount', 'LIKE', "%{$search}%")
                    ->orWhere('invoices.payment_date', 'LIKE', "%{$search}%")
                    ->orWhere('invoices.created_at', 'LIKE', "%{$search}%");

                // Accurate Status Matching: Use exact match if search matches a status
                $statuses = [
                    Invoice::STATUS_PAID,
                    Invoice::STATUS_UNPAID,
                    Invoice::STATUS_DISPUTED,
                    Invoice::STATUS_FAILED,
                    Invoice::STATUS_PENDING,
                    Invoice::STATUS_SUCCESS,
                    Invoice::STATUS_CANCELED,
                    Invoice::STATUS_OVERDUE,
                ];

                if (in_array(ucfirst(strtolower($search)), $statuses)) {
                    $q->orWhere('invoices.status', ucfirst(strtolower($search)));
                } else {
                    $q->orWhere('invoices.status', 'LIKE', "%{$search}%");
                }

                // Search in related partner
                $q->orWhereHas('partner', function ($pq) use ($search) {
                    $pq->where('company_name', 'LIKE', "%{$search}%")
                        ->orWhere('full_name', 'LIKE', "%{$search}%");
                });

                // Search in related borrower
                $q->orWhereHas('borrower', function ($bq) use ($search) {
                    $bq->where('business_name', 'LIKE', "%{$search}%")
                        ->orWhere('first_name', 'LIKE', "%{$search}%")
                        ->orWhere('last_name', 'LIKE', "%{$search}%")
                        ->orWhereRaw("CONCAT(borrowers.first_name, ' ', borrowers.last_name) LIKE ?", ["%{$search}%"]);
                });
            });
        }

        // Build aliases for QueryFilter
        $aliases = [
            'invoice_id' => 'invoices.invoice_id',
            'customer_name' => 'invoices.customer_name',
            'email' => 'invoices.email',
            'payment_amount' => 'invoices.payment_amount',
            'payment_date' => 'invoices.payment_date',
            'status' => 'invoices.status',
        ];

        // Add join-dependent aliases
        if ($sortField === 'partner_name' || isset($rawFilters['partner_name'])) {
            $aliases['partner_name'] = 'partners.full_name';
        }

        // Apply QueryFilter for advanced filtering with "contains" matching
        $query = QueryFilter::apply(
            $query,
            $sortField,
            $sortOrder,
            $filters['filters'] ?? [],
            [
                'aliases' => $aliases,
                'skip_sorting' => $skipSorting,
            ]
        );

        // Pagination
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
     * Get unpaid invoices for a borrower
     * 
     * @param int $borrowerId
     * @return Collection
     */
    public function getUnpaidInvoices(int $borrowerId): Collection
    {
        return Invoice::where('borrower_id', $borrowerId)
            ->where('status', Invoice::STATUS_UNPAID)
            ->where('is_partner_paying', false)
            ->get();
    }

    /**
     * Get invoice statistics for borrower.
     *
     * @param int $borrowerId
     * @param int $partnerId
     * @param array $filters
     * @return array
     */
    public function getStats(int $borrowerId, int $partnerId, array $filters = []): array
    {
        $query = Invoice::where('borrower_id', $borrowerId)->where('is_partner_paying', false);

        if (!empty($partnerId)) {
            $query->where('partner_id', $partnerId);
        }

        if (!empty($filters['date_from'])) {
            $query->whereDate('created_at', '>=', $filters['date_from']);
        }

        if (!empty($filters['date_to'])) {
            $query->whereDate('created_at', '<=', $filters['date_to']);
        }

        $allInvoices = $query->get(['payment_amount', 'status']);

        $totalSent = $allInvoices->sum('payment_amount');

        $paidAmount = $allInvoices->whereIn('status', [Invoice::STATUS_PAID, Invoice::STATUS_SUCCESS])
            ->sum('payment_amount');

        $unpaidAmount = $allInvoices->whereIn('status', [
            Invoice::STATUS_UNPAID,
            Invoice::STATUS_PENDING,
            Invoice::STATUS_OVERDUE
        ])->sum('payment_amount');

        $collectionRate = $totalSent > 0 ? ($paidAmount / $totalSent) * 100 : 0;

        return [
            'invoices_sent'   => $totalSent,
            'paid_invoices'   => $paidAmount,
            'unpaid_invoices' => $unpaidAmount,
            'collection_rate' => round($collectionRate, 2),
        ];
    }
}
