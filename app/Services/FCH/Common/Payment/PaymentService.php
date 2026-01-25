<?php

namespace App\Services\FCH\Common\Payment;

use App\Traits\PaginatesApi;
use App\Models\Invoice\Invoice;
use App\Models\Payment\Payment;
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
                'borrower:id,first_name,last_name'
            ])
            ->select('payments.*');

        // RBAC: Partner Wise (Accurate Code following Borrowers)
        if ($authUser && $authUser->partner) {
            if ($authUser->partner->is_host == 1) {
                if (!empty($filters['partner_id'])) {
                    $query->where('payments.partner_id', $filters['partner_id']);
                }
            } else {
                $query->where('payments.partner_id', $authUser->partner_id);
            }
        }

        // Accurate Date Range: transaction_date (Consistent with InvoiceService)
        if (!empty($filters['date_from'])) {
            $query->whereDate('payments.transaction_date', '>=', $filters['date_from']);
        }
        if (!empty($filters['date_to'])) {
            $query->whereDate('payments.transaction_date', '<=', $filters['date_to']);
        }

        // Compatibility with payee_id
        if (!empty($filters['payee_id'])) {
            $query->where(function ($q) use ($filters) {
                $q->where('payments.partner_id', $filters['payee_id'])
                    ->orWhere('payments.borrower_id', $filters['payee_id']);
            });
        }

        // Status (Compatibility)
        if (!empty($filters['status']) && !is_array($filters['status']) && $filters['status'] !== 'All Status') {
            $query->where('payments.status', strtolower($filters['status']));
        }

        // Accurate Global Search (Following Borrowers Pattern)
        $search = $filters['search'] ?? ($filters['filters']['global']['value'] ?? null);
        if (!empty($search)) {
            $search = trim((string) $search);
            $query->where(function ($q) use ($search) {
                $q->where('payments.transaction_id', 'like', "%{$search}%")
                    ->orWhere('payments.payment_from', 'like', "%{$search}%")
                    ->orWhere('payments.status', 'like', "%{$search}%")
                    ->orWhere('payments.amount', 'like', "%{$search}%")
                    ->orWhereHas('partner', function ($pq) use ($search) {
                        $pq->where('company_name', 'like', "%{$search}%")
                            ->orWhere('full_name', 'like', "%{$search}%");
                    })
                    ->orWhereHas('borrower', function ($bq) use ($search) {
                        $bq->where('business_name', 'like', "%{$search}%")
                            ->orWhere('first_name', 'like', "%{$search}%")
                            ->orWhere('last_name', 'like', "%{$search}%")
                            ->orWhereRaw("CONCAT(borrowers.first_name, ' ', borrowers.last_name) LIKE ?", ["%{$search}%"]);
                    });
            });
        }

        // PrimeReact Structured Filters
        $rawFilters = $filters['filters'] ?? [];

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

        // Handle "company" filter specifically
        if (isset($rawFilters['company'])) {
            $companyFilter = $rawFilters['company'];
            if (isset($companyFilter['constraints']) && is_array($companyFilter['constraints'])) {
                foreach ($companyFilter['constraints'] as $constraint) {
                    $value = $constraint['value'] ?? null;
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

        // Company Sorting
        if ($sortField === 'company') {
            $query->leftJoin('partners', 'payments.partner_id', '=', 'partners.id')
                ->leftJoin('borrowers', 'payments.borrower_id', '=', 'borrowers.id')
                ->orderBy(DB::raw('COALESCE(partners.company_name, borrowers.business_name)'), $sortOrder);
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

        $perPage = isset($filters['per_page']) ? max(1, (int) $filters['per_page']) : 10;
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

        if ($authUser && $authUser->partner && $authUser->partner->is_host != 1) {
            $query->where('partner_id', $authUser->partner_id);
        }

        // Apply same filters as list if needed
        if (!empty($filters['date_from'])) {
            $query->whereDate('transaction_date', '>=', $filters['date_from']);
        }
        if (!empty($filters['date_to'])) {
            $query->whereDate('transaction_date', '<=', $filters['date_to']);
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
    public function show(int $id)
    {
        $payment = Payment::with(['partner', 'borrower'])->findOrFail($id);
        return new PaymentResource($payment);
    }
}
