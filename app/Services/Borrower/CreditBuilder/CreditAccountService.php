<?php

namespace App\Services\Borrower\CreditBuilder;

use App\Models\CreditBuilder\CreditAccount;
use App\Models\CreditBuilder\CreditAccountApplication;
use App\Helpers\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Lang;

use App\Http\Resources\Borrower\CreditBuilder\CreditAccountResource;

class CreditAccountService
{
    /**
     * List credit accounts with filters.
     */
    public function list(array $filters): JsonResponse
    {
        // Switched on by the host, and within the borrower's FICO and time in business.
        $query = CreditAccount::offeredTo(auth()->user()->borrower);



        if (!empty($filters['category']) && $filters['category'] !== 'All Categories') {
            $query->where('credit_category', $filters['category']);
        }


        if (!empty($filters['issuer']) && $filters['issuer'] !== 'All Issuers') {
            $query->where('issuer', $filters['issuer']);
        }

        $query->when(!empty($filters['q']), function ($q) use ($filters) {
            $searchTerm = '%' . $filters['q'] . '%';
            $q->where(
                function ($sub) use ($searchTerm) {
                    $sub->where('issuer', 'like', $searchTerm)
                        ->orWhere('card_name', 'like', $searchTerm);
                }
            );
        });

        // Many cards share a rank; the id breaks ties so every page request
        // orders them the same way and each card is on exactly one page (BUG-138).
        $accounts = $query->orderBy('difficulty_rank')->orderBy('credit_accounts.id')->paginate(16);

        return CreditAccountResource::collection($accounts)->additional([
            'status' => true,
            'message' => __('credit_builder.list_fetched')
        ])->response();
    }

    /**
     * Get unique categories and issuers for filtering.
     */
    public function getFilterOptions(): JsonResponse
    {
        $categories = CreditAccount::whereNotNull('credit_category')
            ->distinct()
            ->orderBy('credit_category')
            ->pluck('credit_category');

        $issuers = CreditAccount::whereNotNull('issuer')
            ->distinct()
            ->orderBy('issuer')
            ->pluck('issuer');

        $statuses = config('credit_account.statuses', ['Requested', 'Processing', 'Approved']);

        return ApiResponse::success([
            'categories' => $categories,
            'issuers' => $issuers,
            'application_statuses' => $statuses
        ], 'Filter options retrieved successfully.');
    }

    /**
     * Handle application request/status update.
     *
     * BY DESIGN (BUG-028, R2-CP-CBA-01): the borrower may set any of these
     * statuses from any other - N/A straight to Approved, or back again. The
     * vendor approves the account outside the portal; this status is only the
     * borrower's own note of where their application stands, so there is no
     * Requested -> Processing -> Approved order to enforce. Not a defect - keep
     * it unenforced (covered by BorrowerCreditBuilderApiTest::
     * a_borrower_may_mark_any_status_from_any_other_by_design).
     */
    public function apply(int $id, ?string $status = null): JsonResponse
    {
        $borrowerId = auth()->user()->entity_id;
        $allowedStatuses = [
            CreditAccountApplication::STATUS_REQUESTED,
            CreditAccountApplication::STATUS_PROCESSING,
            CreditAccountApplication::STATUS_APPROVED,
        ];

        // An unknown account is a 404, not a foreign-key failure; so is one the
        // list would not offer this borrower - switched off by the host, or
        // outside their FICO score or time in business.
        CreditAccount::offeredTo(auth()->user()->borrower)->findOrFail($id);

        // Fallback to first config status if none provided or invalid
        $finalStatus = (in_array($status, $allowedStatuses)) ? $status : $allowedStatuses[0];

        $application = CreditAccountApplication::updateOrCreate(
            [
                'borrower_id' => $borrowerId,
                'credit_account_id' => $id,
            ],
            [
                'status' => $finalStatus,
            ]
        );

        $messageKey = 'credit_builder.status_updated_' . strtolower($finalStatus);
        $message = Lang::has($messageKey) ? __($messageKey) : __('credit_builder.status_updated');

        return ApiResponse::success($application, $message);
    }
}
