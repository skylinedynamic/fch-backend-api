<?php

namespace App\Services\Borrower\Payment;

use App\Http\Resources\Borrower\Payment\PaymentResource;
use App\Models\Borrower\Borrower;
use App\Models\Invoice\Invoice;
use App\Models\Payment\Payment;
use App\Services\Global\Datatable\QueryFilter;
use App\Traits\PaginatesApi;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PaymentService
{
    use PaginatesApi;

    /**
     * List payments with pagination and filtering for borrower.
     */
    public function list(array $filters)
    {
        $authUser = Auth::user();
        $borrowerId = $authUser->entity_id;

        $query = Payment::where('entity_type', Borrower::class)
            ->where('entity_id', $borrowerId)
            ->with([
                'partner:id,full_name',
                'borrower:id,first_name,last_name',
                'invoice:id,invoice_id'
            ])
            ->select('payments.*');

        // Accurate Date Range: transaction_date
        if (!empty($filters['date_from'])) {
            $query->whereDate('payments.transaction_date', '>=', $filters['date_from']);
        }
        if (!empty($filters['date_to'])) {
            $query->whereDate('payments.transaction_date', '<=', $filters['date_to']);
        }

        if (!empty($filters['date_range']) && is_array($filters['date_range'])) {
            if (!empty($filters['date_range'][0])) {
                $query->whereDate('payments.transaction_date', '>=', $filters['date_range'][0]);
            }
            if (!empty($filters['date_range'][1])) {
                $query->whereDate('payments.transaction_date', '<=', $filters['date_range'][1]);
            }
        }

        // Status (Compatibility)
        $statusFilter = $filters['status'] ?? null;
        if (is_array($statusFilter)) {
            $statusFilter = $statusFilter['value'] ?? null;
        }

        if (!empty($statusFilter) && $statusFilter !== 'All Status') {
            $query->where('payments.status', strtolower($statusFilter));
        }

        if (!empty($filters['invoice_id'])) {
            $query->where('payments.invoice_id', $filters['invoice_id']);
        }

        // Global search
        $globalSearch = $filters['filters']['global']['value'] ?? ($filters['search'] ?? null);
        $search = !empty($globalSearch) ? trim((string) $globalSearch) : null;

        if ($search) {
            $query->search($search);
        }

        // PrimeReact Structured Filters
        $rawFilters = $filters['filters'] ?? [];
        unset($rawFilters['global']);

        // Handle structured filter for invoice_id (INV-...)
        if (isset($rawFilters['invoice_id'])) {
            $invoiceFilter = $rawFilters['invoice_id'];
            $constraints = $invoiceFilter['constraints'] ?? [$invoiceFilter];
            if (is_array($constraints)) {
                foreach ($constraints as $c) {
                    $value = $c['value'] ?? null;
                    if ($value) {
                        $query->whereHas('invoice', function ($q) use ($value) {
                            $q->where('invoice_id', 'like', "%{$value}%");
                        });
                    }
                }
            }
            unset($rawFilters['invoice_id']);
        }

        // Manual Date Handling for structured filters
        if (isset($rawFilters['date'])) {
            $dateFilter = $rawFilters['date'];
            if (isset($dateFilter['constraints']) && is_array($dateFilter['constraints'])) {
                foreach ($dateFilter['constraints'] as $constraint) {
                    $value = $constraint['value'] ?? null;
                    $mode = $constraint['matchMode'] ?? 'dateIs';
                    if ($value) {
                        if ($mode === 'dateIs') {
                            $query->whereDate('payments.transaction_date', '=', $value);
                        } elseif ($mode === 'dateBefore') {
                            $query->whereDate('payments.transaction_date', '<', $value);
                        } elseif ($mode === 'dateAfter') {
                            $query->whereDate('payments.transaction_date', '>', $value);
                        }
                    }
                }
            }
            unset($rawFilters['date']);
        }

        // Sorting
        $sortField = $filters['sort_field'] ?? 'transaction_date';
        $sortOrder = $filters['sort_order'] ?? 'desc';

        $sortFieldMap = [
            'date' => 'payments.transaction_date',
            'from' => 'payments.payment_from',
        ];

        $actualSortField = $sortFieldMap[$sortField] ?? $sortField;
        if (!str_contains($actualSortField, '.')) {
            $actualSortField = 'payments.' . $actualSortField;
        }

        $skipSorting = false;
        if ($sortField === 'invoice_id') {
            $query->leftJoin('invoices', 'payments.invoice_id', '=', 'invoices.id')
                ->orderBy('invoices.invoice_id', $sortOrder);
            $skipSorting = true;
        }

        // Apply remaining filters via QueryFilter
        $query = QueryFilter::apply(
            $query,
            $actualSortField,
            $sortOrder,
            $rawFilters,
            [
                'skip_sorting' => $skipSorting,
                'aliases' => [
                    'from' => 'payment_from',
                    'transaction_id' => 'transaction_id',
                    'amount' => 'amount',
                    'status' => 'status',
                    'payment_type' => 'payment_type'
                ]
            ]
        );

        // Perform stats calculation on fully filtered query JUST BEFORE pagination
        $stats = $this->getStatsFromQuery(clone $query);

        $perPage = isset($filters['per_page']) ? max(1, min((int) $filters['per_page'], 100)) : 10;
        $page = isset($filters['page']) ? max(1, (int) $filters['page']) : 1;

        $paginator = $this->paginateBuilderWith($query, $perPage, $page);

        return $this->respondWithPaginationResource(
            $paginator,
            PaymentResource::class,
            __('payment.list_retrieved'),
            200,
            ['stats' => $stats]
        );
    }

    /**
     * Get payment statistics from cloned Query Builder using optimized SQL aggregation.
     *
     * @param mixed $query
     * @return array
     */
    public function getStatsFromQuery($query): array
    {
        $cloned = clone $query;
        $cloned->setEagerLoads([]);
        $cloned->getQuery()->columns = null;
        $cloned->getQuery()->orders = null;

        $result = $cloned->selectRaw("
            COALESCE(SUM(payments.amount), 0) as total_payments,
            COALESCE(SUM(CASE WHEN payments.status IN (?, ?) THEN payments.amount ELSE 0 END), 0) as completed,
            COALESCE(SUM(CASE WHEN payments.status = ? THEN payments.amount ELSE 0 END), 0) as pending,
            COALESCE(SUM(CASE WHEN payments.status = ? THEN payments.amount ELSE 0 END), 0) as processing
        ", [
            Payment::STATUS_SUCCEEDED,
            'success',
            Payment::STATUS_PENDING,
            Payment::STATUS_PROCESSING
        ])->first();

        $totalPayments = (float) ($result->total_payments ?? 0);
        $completed = (float) ($result->completed ?? 0);
        $pending = (float) ($result->pending ?? 0);
        $processing = (float) ($result->processing ?? 0);

        return [
            'total_payments' => currencyFormat($totalPayments),
            'completed' => currencyFormat($completed),
            'pending' => currencyFormat($pending),
            'processing' => currencyFormat($processing),
        ];
    }

    /**
     * Get payment statistics for borrower.
     */
    public function getStats(array $filters): array
    {
        $authUser = Auth::user();
        $borrowerId = $authUser->entity_id;

        $query = Payment::where('is_partner_paying', false)->where('borrower_id', $borrowerId);

        if (!empty($filters['date_from'])) {
            $query->whereDate('transaction_date', '>=', $filters['date_from']);
        }
        if (!empty($filters['date_to'])) {
            $query->whereDate('transaction_date', '<=', $filters['date_to']);
        }

        if (!empty($filters['date_range']) && is_array($filters['date_range'])) {
            if (isset($filters['date_range'][0]) && isset($filters['date_range'][1])) {
                $query->whereBetween('transaction_date', [$filters['date_range'][0], $filters['date_range'][1]]);
            }
        }

        return $this->getStatsFromQuery($query);
    }

    /**
     * Get payment details for borrower.
     */
    public function find($id)
    {
        $authUser = Auth::user();
        $borrowerId = $authUser->entity_id;

        $payment = Payment::with(['partner', 'borrower', 'invoice'])
            ->where('entity_type', Borrower::class)
            ->where('entity_id', $borrowerId)
            ->find($id);

        return $payment ? new PaymentResource($payment) : null;
    }
}
