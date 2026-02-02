<?php

namespace App\Services\Borrower\Invoice;

use App\Models\Invoice\Invoice;
use Illuminate\Database\Eloquent\Collection;

class InvoiceService
{
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
        $query = Invoice::where('borrower_id', $borrowerId);

        if (!empty($partnerId)) {
            $query->where('partner_id', $partnerId);
        }

        if (!empty($filters['date_from'])) {
            $query->whereDate('created_at', '>=', $filters['date_from']);
        }

        if (!empty($filters['date_to'])) {
            $query->whereDate('created_at', '<=', $filters['date_to']);
        }

        if (!empty($filters['cycle']) && $filters['cycle'] !== Invoice::CYCLE_ALL) {
            $query->where('cycle', $filters['cycle']);
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
