<?php

namespace App\Services\FCH\Common\Commission;

use App\Helpers\ApiResponse;
use App\Http\Resources\FCH\Common\Commission\CommissionResource;
use App\Models\Commission\Commission;
use App\Services\Global\Datatable\QueryFilter;
use App\Traits\PaginatesApi;
use Illuminate\Support\Facades\Auth;

class CommissionService
{
    use PaginatesApi;

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

        $query = QueryFilter::apply(
            $query,
            $sortField,
            $sortOrder,
            $filters['filters'] ?? [],
            [
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
     * Get commission by ID with relationships.
     */
    public function getCommissionById(int $id)
    {
        return CommissionViewer::for(Auth::user())->scope(Commission::query())
            ->with(['fundingApplication', 'partner', 'borrower.partner', 'borrower.assignedUser', 'payout.invoice', 'payout.payment'])
            ->findOrFail($id);
    }
}
