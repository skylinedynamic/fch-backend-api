<?php

namespace App\Services\FCH\Common\Commission;

use App\Helpers\ApiResponse;
use App\Http\Resources\FCH\Common\Commission\CommissionResource;
use App\Models\Borrower\FundingApplication;
use App\Models\Commission\Commission;
use App\Models\Partner\Partner;
use App\Services\Global\Datatable\QueryFilter;
use App\Traits\PaginatesApi;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class CommissionService
{
    use PaginatesApi;

    /** The commissions columns the list may be sorted by as they are. */
    private const SORT_COLUMNS = [
        'id', 'created_at', 'commission_status', 'payment_status', 'payout_date',
        'total_commission_amount', 'admin_commission_amount', 'partner_commission_amount',
    ];

    /**
     * Get a list of commissions.
     */
    public function getCommissions(array $filters)
    {
        $viewer = CommissionViewer::for(Auth::user());
        $query = $viewer->scope(Commission::query())
        // A commission outlives an archived client: the deal was funded and the
        // money is still owed or paid. Loading the archived (soft-deleted) rows
        // keeps those deals on the list with their names - without them one such
        // commission used to fail the whole page, showing $0 and no rows.
        ->with([
            'fundingApplication' => fn($q) => $q->withTrashed(),
            'partner' => fn($q) => $q->withTrashed(),
            'borrower' => fn($q) => $q->withTrashed(),
        ]);

        // A host staff member's row shows their payout, worked out from these.
        if ($viewer->is(CommissionViewer::HOST_STAFF)) {
            $query->with(['borrower.partner', 'borrower.assignedUser', 'payout.invoice']);
        }

        // Filter by Partner ID
        if (isset($filters['partner_id']) && !empty($filters['partner_id'])) {
            $query->where('partner_id', $filters['partner_id']);
        }

        // Snapshot before the payment/commission status, search and column
        // filters, so the summary cards show true totals instead of recounting
        // whatever the table is narrowed to (the host's Partner filter still applies).
        $statsQuery = clone $query;

        // Filter by Payment Status
        if (isset($filters['payment_status']) && !empty($filters['payment_status'])) {
            $query->where('payment_status', $filters['payment_status']);
        }

        // Filter by Commission Status
        if (isset($filters['commission_status']) && !empty($filters['commission_status'])) {
            $query->where('commission_status', $filters['commission_status']);
        }

        // Apply Search
        if (!empty($filters['search'])) {
            $query->search(trim((string) $filters['search']));
        }

        $sortField = $filters['sort_field'] ?? 'id';
        $sortOrder = strtolower($filters['sort_order'] ?? 'desc') === 'asc' ? 'asc' : 'desc';

        $this->applySort($query, (string) $sortField, $sortOrder);

        $query = QueryFilter::apply(
            $query,
            $sortField,
            $sortOrder,
            $filters['filters'] ?? [],
            [
                'skip_sorting' => true,
                'aliases' => [
                    'created_at' => 'date_range',
                ]
            ]
        );

        $perPage = isset($filters['per_page']) ? max(1, (int) $filters['per_page']) : 10;
        $page = isset($filters['page']) ? max(1, (int) $filters['page']) : 1;

        // The cards, in this viewer's terms (see CommissionViewer).
        $statistics = $viewer->summarize($statsQuery);

        $paginator = $this->paginateBuilderWith($query, $perPage, $page);

        return $this->respondWithPaginationResource(
            $paginator,
            CommissionResource::class,
            __('commission.retrieved'),
            200,
            ['statistics' => $statistics]
        );
    }

    /**
     * Sort by one of the list's columns (BUG-073). Borrower, Partner, Requested
     * and Funded Amount are not commissions columns - they come from the
     * funding application and the partner, archived ones included as on the
     * list. Any other field sorts by the key instead of reaching the database.
     */
    private function applySort(Builder $query, string $field, string $order): void
    {
        $related = [
            'borrower_name' => FundingApplication::withTrashed()->select('full_name')
                ->whereColumn('funding_applications.id', 'commissions.funding_application_id'),
            'requested_amount' => FundingApplication::withTrashed()->select('requested_amount')
                ->whereColumn('funding_applications.id', 'commissions.funding_application_id'),
            'funded_amount' => FundingApplication::withTrashed()->select('total_funded_amount')
                ->whereColumn('funding_applications.id', 'commissions.funding_application_id'),
            'partner_name' => Partner::withTrashed()->select('full_name')
                ->whereColumn('partners.id', 'commissions.partner_id'),
        ];

        if (isset($related[$field])) {
            $query->orderBy($related[$field], $order);
        } elseif (in_array($field, self::SORT_COLUMNS, true)) {
            $query->orderBy('commissions.' . $field, $order);
        }

        // A steady order for ties and the fallback for anything else.
        $query->orderBy('commissions.id', $order);
    }

    /**
     * Get commission by ID with relationships.
     */
    public function getCommissionById(int $id)
    {
        return CommissionViewer::for(Auth::user())->scope(Commission::query())
            ->with(['fundingApplication', 'partner', 'borrower.partner', 'borrower.assignedUser', 'payout.invoice', 'payout.payment'])
            ->findOrFail($id);
    }
}
