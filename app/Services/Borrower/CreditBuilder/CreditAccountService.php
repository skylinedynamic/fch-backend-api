<?php

namespace App\Services\Borrower\CreditBuilder;

use App\Models\CreditBuilder\CreditAccount;
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
        $query = CreditAccount::query();

        if (!empty($filters['tier']) && $filters['tier'] !== 'All Tier') {
            $query->where('portal_tier_unlock', $filters['tier']);
        }

        if (!empty($filters['category']) && $filters['category'] !== 'All Categories') {
            $query->where('credit_category', $filters['category']);
        }

        if (!empty($filters['bureau']) && $filters['bureau'] !== 'All Bureau') {
            $query->where('approval_level', $filters['bureau']);
        }

        if (!empty($filters['issuer']) && $filters['issuer'] !== 'All Issuers') {
            $query->where('issuer', $filters['issuer']);
        }

        $query->when(!empty($filters['q']), function ($q) use ($filters) {
            $searchTerm = '%' . $filters['q'] . '%';
            $q->where(function ($sub) use ($searchTerm) {
                    $sub->where('issuer', 'like', $searchTerm)
                        ->orWhere('card_name', 'like', $searchTerm);
                }
                );
            });

        $accounts = $query->orderBy('difficulty_rank')->paginate(16);

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
     */
    public function apply(int $id, ?string $status = null): JsonResponse
    {
        $borrowerId = auth()->user()->entity_id;
        $allowedStatuses = ['Requested', 'Processing', 'Approved'];

        // Fallback to first config status if none provided or invalid
        $finalStatus = (in_array($status, $allowedStatuses)) ? $status : $allowedStatuses[0];

        $application = \App\Models\CreditBuilder\CreditAccountApplication::updateOrCreate(
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
