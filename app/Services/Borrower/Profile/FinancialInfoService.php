<?php

namespace App\Services\Borrower\Profile;

use App\Models\Company\Company;
use Illuminate\Support\Facades\Auth;

class FinancialInfoService
{
    /**
     * Get financial information for the authenticated borrower
     *
     * @return Company|null
     */
    public function getFinancialInfo(): ?Company
    {
        $company = Company::where('user_id', Auth::id())->first();
        
        if (!$company) {
            return null;
        }

        return $company;
    }

    /**
     * Update financial information for the authenticated borrower
     *
     * @param array $data
     * @return Company|null
     */
    public function updateFinancialInfo(array $data): ?Company
    {
        $company = Company::where('user_id', Auth::id())->first();
        
        if (!$company) {
            return null;
        }

        $company = Company::updateOrCreate(
            ['user_id' => Auth::id()],
            ['currency',
            'years_in_business',
            'monthly_revenue',
            'annual_revenue']
        );

        return $company->fresh();
    }
}