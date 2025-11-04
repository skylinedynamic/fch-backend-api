<?php

namespace App\Services\Borrower\Profile;

use App\Models\Business\Business;
use Illuminate\Support\Facades\Auth;

class FinancialInfoService
{
    /**
     * Get financial information for the authenticated borrower
     *
     * @return Business|null
     */
    public function getFinancialInfo(): ?Business
    {
        $business = Business::where('user_id', Auth::id())->first();
        
        if (!$business) {
            return null;
        }

        return $business;
    }

    /**
     * Update financial information for the authenticated borrower
     *
     * @param array $data
     * @return Company|null
     */
    public function updateFinancialInfo(array $data): ?Business
    {
        $business = Business::where('user_id', Auth::id())->first();
        
        if (!$business) {
            return null;
        }

        $business = Business::updateOrCreate(
            ['user_id' => Auth::id()],
            ['currency',
            'years_in_business',
            'monthly_revenue',
            'annual_revenue']
        );

        return $business->fresh();
    }
}