<?php

namespace App\Services\FCH\Common\Payment;

use App\Models\Payment\Payment;
use App\Models\Invoice\Invoice;
use App\Traits\PaginatesApi;
use App\Http\Resources\FCH\Common\Payment\PaymentResource;
use App\Services\Global\Datatable\QueryFilter;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

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
            ->with(['partner', 'borrower'])
            ->select('payments.*');

        // Filter by partner_id if non-host
        if ($authUser && $authUser->partner && $authUser->partner->is_host != 1) {
            $query->where('partner_id', $authUser->partner_id);
        }

        // Apply filters
        // Date Range
        if (!empty($filters['date_from'])) {
            $query->whereDate('transaction_date', '>=', $filters['date_from']);
        }
        if (!empty($filters['date_to'])) {
            $query->whereDate('transaction_date', '<=', $filters['date_to']);
        }

        // Status
        if (!empty($filters['status']) && $filters['status'] !== 'All Status') {
            $query->where('status', strtolower($filters['status']));
        }

        // Search / Payee
        if (!empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('transaction_id', 'like', "%{$search}%")
                    ->orWhere('payment_from', 'like', "%{$search}%")
                    ->orWhereHas('partner', function ($pq) use ($search) {
                        $pq->where('company_name', 'like', "%{$search}%")
                            ->orWhere('full_name', 'like', "%{$search}%");
                    })
                    ->orWhereHas('borrower', function ($bq) use ($search) {
                        $bq->where('business_name', 'like', "%{$search}%")
                            ->orWhere('first_name', 'like', "%{$search}%")
                            ->orWhere('last_name', 'like', "%{$search}%");
                    });
            });
        }

        // Add additional filters sent from frontend
        if (!empty($filters['payee_id'])) {
            $query->where(function ($q) use ($filters) {
                $q->where('partner_id', $filters['payee_id'])
                    ->orWhere('borrower_id', $filters['payee_id']);
            });
        }

        if (!empty($filters['cycle']) && $filters['cycle'] !== 'All Cycle') {
        }

        // Sorting
        $sortField = $filters['sort_field'] ?? 'transaction_date';
        $sortOrder = $filters['sort_order'] ?? 'desc';
        // Map frontend fields to backend if needed
        $fieldMap = [
            'date' => 'transaction_date',
            'from' => 'payment_from',
            'transaction_id' => 'transaction_id'
        ];
        $actualField = $fieldMap[$sortField] ?? $sortField;
        $query->orderBy($actualField, $sortOrder);

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
            'pending' => $allPayments->where('status', 'pending')->count(),
            'failed' => $allPayments->where('status', 'failed')->count(),
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
