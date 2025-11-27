<?php

namespace App\Services\Borrower\Profile;

use App\Models\Borrower\Borrower;
use Illuminate\Support\Facades\Auth;

class FinancialInfoService
{

    public function getFinancialInfo()
    {
        $borrower = Auth::user()->entity;
        
        if (!$borrower) {
            return null;
        }

        return $borrower;
    }

    public function updateFinancialInfo(array $data)
    {
        $borrower = Auth::user()->entity;
        
        if (!$borrower) {
            return null;
        }

        $borrower->update(
            ['currency' => $data['currency'] ?? $borrower->currency,
            'years_in_business' => $data['years_in_business'] ?? $borrower->years_in_business,
            'monthly_revenue' => $data['monthly_revenue'] ?? $borrower->monthly_revenue,
            'annual_revenue' => $data['annual_revenue'] ?? $borrower->annual_revenue]
        );

        return $borrower->fresh();
    }   
}   