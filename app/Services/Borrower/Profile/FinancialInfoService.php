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
            'annual_revenue' => $data['annual_revenue'] ?? $borrower->annual_revenue,
            'current_business_debt' => array_key_exists('current_business_debt', $data) ? $data['current_business_debt'] : $borrower->current_business_debt,
            'average_monthly_deposits' => array_key_exists('average_monthly_deposits', $data) ? $data['average_monthly_deposits'] : $borrower->average_monthly_deposits,
            'monthly_deposit_count' => array_key_exists('monthly_deposit_count', $data) ? $data['monthly_deposit_count'] : $borrower->monthly_deposit_count,
            'avg_bank_balance' => array_key_exists('avg_bank_balance', $data) ? $data['avg_bank_balance'] : $borrower->avg_bank_balance,
            'nsf_count' => array_key_exists('nsf_count', $data) ? $data['nsf_count'] : $borrower->nsf_count,
            'negative_days' => array_key_exists('negative_days', $data) ? $data['negative_days'] : $borrower->negative_days]
        );

        return $borrower->fresh();
    }   
}   