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
}
