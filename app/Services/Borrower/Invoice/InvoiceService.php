<?php

namespace App\Services\Borrower\Invoice;

use App\Http\Resources\Borrower\Invoice\InvoiceResource;
use App\Models\Borrower\Borrower;
use App\Models\Common\AppConfiguration;
use App\Models\Invoice\Invoice;
use App\Services\Global\Datatable\QueryFilter;
use App\Traits\PaginatesApi;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;

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
            ->where('is_partner_paying', false)
            ->where(function ($q) use ($borrowerId) {
                $q->where('invoices.borrower_id', $borrowerId)
                    ->orWhere(function ($sub) use ($borrowerId) {
                        $sub->where('invoices.entity_type', Borrower::class)
                            ->where('invoices.entity_id', $borrowerId);
                    });
            });

        if (!empty($partnerId)) {
            $query->where('invoices.partner_id', $partnerId);
        }

        // Apply date range filters
        if (!empty($filters['date_from'])) {
            $query->whereDate('invoices.created_at', '>=', $filters['date_from']);
        }
        if (!empty($filters['date_to'])) {
            $query->whereDate('invoices.created_at', '<=', $filters['date_to']);
        }

        // Filter by status dropdown
        if (!empty($filters['status']) && $filters['status'] !== Invoice::STATUS_ALL) {
            $query->where('invoices.status', $filters['status']);
        }

        $query->with([
            'partner:id,full_name,company_name,email',
            'borrower:id,first_name,last_name,business_name,email'
        ])->select('invoices.*');

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
                    ->orWhere('invoices.type', 'LIKE', "%{$search}%")
                    ->orWhere('invoices.payment_amount', 'LIKE', "%{$search}%")
                    ->orWhere('invoices.payment_date', 'LIKE', "%{$search}%")
                    ->orWhere('invoices.created_at', 'LIKE', "%{$search}%");

                // Accurate Status Matching: Use exact match if search matches a status
                $statuses = [
                    Invoice::STATUS_PAID,
                    Invoice::STATUS_UNPAID,
                    Invoice::STATUS_DISPUTED,
                    Invoice::STATUS_FAILED,
                    Invoice::STATUS_PROCESSING,
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
            'type' => 'invoices.type',
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

        // Perform stats calculation on fully filtered query JUST BEFORE pagination
        $stats = $this->getStatsFromQuery(clone $query);

        // Pagination
        $perPage = isset($filters['per_page']) ? max(1, (int) $filters['per_page']) : 10;
        $page = isset($filters['page']) ? max(1, (int) $filters['page']) : 1;

        $paginator = $this->paginateBuilderWith($query, $perPage, $page);

        return $this->respondWithPaginationResource(
            $paginator,
            InvoiceResource::class,
            __('invoice.list_retrieved'),
            200,
            ['stats' => $stats]
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
        return Invoice::where(function ($q) use ($borrowerId) {
            $q->where('borrower_id', $borrowerId)
                ->orWhere(function ($sub) use ($borrowerId) {
                    $sub->where('entity_type', Borrower::class)
                        ->where('entity_id', $borrowerId);
                });
        })
            ->where('status', Invoice::STATUS_UNPAID)
            ->where('is_partner_paying', false)
            ->get();
    }

    /**
     * Get invoice statistics from cloned Query Builder using optimized SQL aggregation.
     */
    public function getStatsFromQuery($query): array
    {
        $cloned = clone $query;
        $cloned->setEagerLoads([]);
        $cloned->getQuery()->columns = null;
        $cloned->getQuery()->orders = null;

        $result = $cloned->selectRaw("
            COALESCE(SUM(invoices.payment_amount), 0) as total_sent,
            COALESCE(SUM(CASE WHEN invoices.status IN (?, ?) THEN invoices.payment_amount ELSE 0 END), 0) as paid_amount,
            COALESCE(SUM(CASE WHEN invoices.status IN (?, ?, ?) THEN invoices.payment_amount ELSE 0 END), 0) as unpaid_amount
        ", [
            Invoice::STATUS_PAID,
            Invoice::STATUS_SUCCESS,
            Invoice::STATUS_UNPAID,
            Invoice::STATUS_PENDING,
            Invoice::STATUS_OVERDUE
        ])->first();

        $totalSent = (float) ($result->total_sent ?? 0);
        $paidAmount = (float) ($result->paid_amount ?? 0);
        $unpaidAmount = (float) ($result->unpaid_amount ?? 0);
        $collectionRate = $totalSent > 0 ? ($paidAmount / $totalSent) * 100 : 0;

        return [
            'invoices_sent' => currencyFormat($totalSent),
            'paid_invoices' => currencyFormat($paidAmount),
            'unpaid_invoices' => currencyFormat($unpaidAmount),
            'collection_rate' => round($collectionRate, 2),
        ];
    }

    /**
     * Get invoice statistics for borrower using optimized SQL aggregation.
     *
     * @param int $borrowerId
     * @param int $partnerId
     * @param array $filters
     * @return array
     */
    public function getStats(int $borrowerId, int $partnerId = 0, array $filters = []): array
    {
        $query = Invoice::query()
            ->where('is_partner_paying', false)
            ->where(function ($q) use ($borrowerId) {
                $q->where('borrower_id', $borrowerId)
                    ->orWhere(function ($sub) use ($borrowerId) {
                        $sub->where('entity_type', Borrower::class)
                            ->where('entity_id', $borrowerId);
                    });
            });

        if (!empty($partnerId)) {
            $query->where('partner_id', $partnerId);
        }

        if (!empty($filters['date_from'])) {
            $query->whereDate('created_at', '>=', $filters['date_from']);
        }

        if (!empty($filters['date_to'])) {
            $query->whereDate('created_at', '<=', $filters['date_to']);
        }

        return $this->getStatsFromQuery($query);
    }

    /**
     * Find single invoice for authorized borrower.
     * 
     * @param string|int $id
     * @return Invoice|null
     */
    public function find($id): ?Invoice
    {
        $user = auth()->user();
        $borrowerId = $user->entity_id;
        $partnerId = $user->partner_id;

        return Invoice::with(['partner', 'borrower', 'subscription'])
            ->where(function ($q) use ($id) {
                $q->where('id', $id)
                    ->orWhere('invoice_id', $id);
            })
            ->where('borrower_id', $borrowerId)
            ->where('partner_id', $partnerId)
            ->where('is_partner_paying', false)
            ->first();
    }

    /**
     * Download Invoice PDF for borrower.
     */
    public function downloadInvoice($id)
    {
        $invoice = $this->find($id);
        if (!$invoice)
            throw new \Exception(__('invoice.not_found'));

        $business_info = $this->getBusinessInfo();

        Log::info('Invoice business info for borrower', ['invoice_id' => $id, 'business_info' => $business_info]);

        $pdf = Pdf::loadView('invoices.download', compact('invoice', 'business_info'))
            ->setPaper('a4')
            ->setWarnings(false);

        return $pdf;
    }

    /**
     * Helper to get business info for templates with white-label support.
     */
    private function getBusinessInfo(): array
    {
        $targetPartner = getConfigPartner();
        $branding = AppConfiguration::where('partner_id', $targetPartner->id)->where('module', AppConfiguration::MODULE_BRANDING)->pluck('value', 'key')->toArray();

        return [
            'company_name' => $branding['site_name'] ?? '',
            'email' => $targetPartner->email ?? '',
            'phone_number' => $targetPartner->phone_number ?? '',
            'address_line_1' => $targetPartner->address_line_1 ?? '',
            'city' => $targetPartner->city ?? '',
            'state' => $targetPartner->state ?? '',
            'postal_code' => $targetPartner->postal_code ?? '',
            'country' => $targetPartner->country ?? '',
            'website' => $targetPartner->website ?? '',
            // !empty, not isset: an empty-string secondary_logo (a partner with no
            // logo uploaded, e.g. partner_id 37/40) is isset() === true, which built
            // a URL pointing at the bare "storage" folder with no filename - the
            // download.blade.php view then tried to file_get_contents() that
            // directory and failed.
            'business_logo_path' => !empty($branding['secondary_logo']) ? encodeUrl(asset('storage/' . $branding['secondary_logo'])) : '',
        ];
    }
}
