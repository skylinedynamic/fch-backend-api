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
        $business = Auth::user()->business;
        $business = Business::where('id', $business?->id)->first();
        
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
        $business = Auth::user()->business;
        $business = Business::where('id', $business?->id)->first();
        
        if (!$business) {
            return null;
        }

        $business = Business::updateOrCreate(
            ['id' => $business->id],
            ['currency' => $data['currency'] ?? $business->currency,
            'years_in_business' => $data['years_in_business'] ?? $business->years_in_business,
            'monthly_revenue' => $data['monthly_revenue'] ?? $business->monthly_revenue,
            'annual_revenue' => $data['annual_revenue'] ?? $business->annual_revenue]
        );

        return $business->fresh();
    }
}