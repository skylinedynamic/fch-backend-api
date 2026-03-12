<?php

namespace App\Services\FCH\Common\Payment;

use App\Traits\PaginatesApi;
use App\Models\Invoice\Invoice;
use App\Models\Payment\Payment;
use App\Models\Employee\Employee;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use App\Services\Global\Datatable\QueryFilter;
use App\Http\Resources\FCH\Common\Payment\PaymentResource;

class PaymentService
{
    use PaginatesApi;

    /**
     * List payments with pagination and filtering.
     */
    public function list(array $filters)
    {
        $authUser = Auth::user();
        $query = Payment::query()
            ->with([
                'partner:id,full_name',
                'borrower:id,first_name,last_name',
                'invoice:id,invoice_id'
            ])
            ->select('payments.*');

        // RBAC: Partner Wise (Accurate Code following Borrowers)
        if (!empty($authUser?->partner) && (int) ($authUser->partner->is_host ?? 0) === 0) {
            $query->where('partner_id', $authUser->partner_id);
        }

        // Accurate Date Range: transaction_date (Consistent with InvoiceService)
        // Accurate Date Range Filtering (Inclusive of Full Day)
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

        // Compatibility with payee_id
        if (!empty($filters['payee_id'])) {
            $query->where(function ($q) use ($filters) {
                $q->where('payments.partner_id', $filters['payee_id'])
                    ->orWhere('payments.borrower_id', $filters['payee_id']);
            });
        }

        // Status (Compatibility)
        // Status (Compatibility)
        $statusFilter = $filters['status'] ?? null;
        if (is_array($statusFilter)) {
            $statusFilter = $statusFilter['value'] ?? null;
        }

        if (!empty($statusFilter) && $statusFilter !== 'All Status') {
            $query->where('payments.status', strtolower($statusFilter));
        }

        // Ensure we don't double filter in QueryFilter
        if (isset($rawFilters['status'])) {
            unset($rawFilters['status']);
        }
        if (!empty($filters['invoice_id'])) {
            $query->where('payments.invoice_id', $filters['invoice_id']);
        }

        // Efficiently extract global search term
        $globalSearch = $filters['filters']['global']['value'] ?? ($filters['search'] ?? null);
        $search = !empty($globalSearch) ? trim((string) $globalSearch) : null;

        if ($search) {
            $query->search($search);
        }

        // PrimeReact Structured Filters
        $rawFilters = $filters['filters'] ?? [];
        unset($rawFilters['global']); // Prevent QueryFilter from trying to search a 'global' column

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

        // Handle "partner_name" filter
        if (isset($rawFilters['partner_name'])) {
            $partnerNameFilter = $rawFilters['partner_name'];
            $constraints = $partnerNameFilter['constraints'] ?? [$partnerNameFilter];
            if (is_array($constraints)) {
                foreach ($constraints as $c) {
                    $value = $c['value'] ?? null;
                    if ($value) {
                        $query->whereHas('partner', function ($pq) use ($value) {
                            $pq->where('full_name', 'like', "%{$value}%");
                        });
                    }
                }
            }
            unset($rawFilters['partner_name']);
        }

        // Handle "borrower_name" filter
        if (isset($rawFilters['borrower_name'])) {
            $borrowerNameFilter = $rawFilters['borrower_name'];
            $constraints = $borrowerNameFilter['constraints'] ?? [$borrowerNameFilter];
            if (is_array($constraints)) {
                foreach ($constraints as $c) {
                    $value = $c['value'] ?? null;
                    if ($value) {
                        $query->whereHas('borrower', function ($bq) use ($value) {
                            $bq->where('first_name', 'like', "%{$value}%")
                                ->orWhere('last_name', 'like', "%{$value}%")
                                ->orWhereRaw("CONCAT(first_name, ' ', last_name) LIKE ?", ["%{$value}%"]);
                        });
                    }
                }
            }
            unset($rawFilters['borrower_name']);
        }

        // Handle "company" filter specifically
        if (isset($rawFilters['company'])) {
            $companyFilter = $rawFilters['company'];
            $constraints = $companyFilter['constraints'] ?? [$companyFilter];
            if (is_array($constraints)) {
                foreach ($constraints as $c) {
                    $value = $c['value'] ?? null;
                    if ($value) {
                        $query->where(function ($q) use ($value) {
                            $q->whereHas('partner', function ($pq) use ($value) {
                                $pq->where('company_name', 'like', "%{$value}%");
                            })->orWhereHas('borrower', function ($bq) use ($value) {
                                $bq->where('business_name', 'like', "%{$value}%");
                            });
                        });
                    }
                }
            }
            unset($rawFilters['company']);
        }

        // Compatibility with payee_id
        if (!empty($filters['payee_id'])) {
            $query->where(function ($q) use ($filters) {
                $q->where('payments.partner_id', $filters['payee_id'])
                    ->orWhere('payments.borrower_id', $filters['payee_id']);
            });
            unset($rawFilters['payee_id']);
        }

        // Status (Compatibility)


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

        // Sorting for Custom Fields
        if ($sortField === 'company') {
            $query->leftJoin('partners', 'payments.partner_id', '=', 'partners.id')
                ->leftJoin('borrowers', 'payments.borrower_id', '=', 'borrowers.id')
                ->orderBy(DB::raw('COALESCE(partners.company_name, borrowers.business_name)'), $sortOrder);
            $skipSorting = true;
        } elseif ($sortField === 'invoice_id') {
            $query->leftJoin('invoices', 'payments.invoice_id', '=', 'invoices.id')
                ->orderBy('invoices.invoice_id', $sortOrder);
            $skipSorting = true;
        } elseif ($sortField === 'partner_name') {
            $query->leftJoin('partners', 'payments.partner_id', '=', 'partners.id')
                ->orderBy('partners.full_name', $sortOrder);
            $skipSorting = true;
        } elseif ($sortField === 'borrower_name') {
            $query->leftJoin('borrowers', 'payments.borrower_id', '=', 'borrowers.id')
                ->orderBy(DB::raw("CONCAT(borrowers.first_name, ' ', borrowers.last_name)"), $sortOrder);
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

        $perPage = isset($filters['per_page']) ? max(1, min((int) $filters['per_page'], 100)) : 10;
        $page = isset($filters['page']) ? max(1, (int) $filters['page']) : 1;

        $paginator = $this->paginateBuilderWith($query, $perPage, $page);

        return $this->respondWithPaginationResource(
            $paginator,
            PaymentResource::class,
            __('payment.list_retrieved')
        );
    }

    /**
     * Get payment statistics.
     */
    public function getStats(array $filters): array
    {
        $authUser = Auth::user();
        $query = Payment::query();

        if (!empty($authUser?->partner) && (int) ($authUser->partner->is_host ?? 0) === 0) {
            $query->where('partner_id', $authUser->partner_id);
        }

        // Apply same filters as list if needed
        if (!empty($filters['date_from'])) {
            $query->whereDate('transaction_date', '>=', $filters['date_from']);
        }
        if (!empty($filters['date_to'])) {
            $query->whereDate('transaction_date', '<=', $filters['date_to']);
        }

        // Accurate Date Range (Consistent with list)
        if (!empty($filters['date_range']) && is_array($filters['date_range'])) {
            if (isset($filters['date_range'][0]) && isset($filters['date_range'][1])) {
                $query->whereBetween('transaction_date', [$filters['date_range'][0], $filters['date_range'][1]]);
            }
        }

        $allPayments = $query->get(['amount', 'status']);

        return [
            'total_payments' => $allPayments->sum('amount'),
            'completed' => $allPayments->whereIn('status', ['succeeded', 'success'])->sum('amount'),
            'pending' => $allPayments->where('status', 'pending')->sum('amount'),
            'failed' => $allPayments->where('status', 'failed')->sum('amount'),
        ];
    }

    /**
     * Get payment details.
     */
    public function find($id)
    {
        $payment = Payment::with(['partner', 'borrower', 'invoice'])->find($id);
        return $payment ? new PaymentResource($payment) : null;
    }
}
