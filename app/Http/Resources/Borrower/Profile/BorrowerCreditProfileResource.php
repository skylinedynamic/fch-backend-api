<?php

namespace App\Http\Resources\Borrower\Profile;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

class BorrowerCreditProfileResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $timezone = $request->header('X-Timezone') ?? config('app.timezone');
        
        return [
            'id' => $this->id,
            'personal_info' => $this->personal_info ?? [
                'name' => $this->borrower?->first_name . ' ' . $this->borrower?->last_name,
                'dob' => $this->borrower?->date_of_birth ? Carbon::parse($this->borrower?->date_of_birth)->timezone($timezone)->format('Y-m-d') : null,
                'ssn' => $this->borrower?->last_4_ssn,
                'email' => $this->borrower?->email,
                'phone' => $this->borrower?->phone_number,
            ],
            'addresses' => $this->addresses->map(fn($addr) => [
                'type' => $addr->type,
                'street' => $addr->street,
                'city' => $addr->city,
                'state' => $addr->state,
                'zip' => $addr->zip,
                'date_reported' => $addr->date_reported,
            ]),
            'metrics' => [
                'fico_score' => $this->fico_score,
                'paydex_score' => $this->paydex_score,
                'experian_intelliscore' => $this->experian_intelliscore,
                'equifax_score' => $this->equifax_score,
                'active_tradelines' => $this->active_tradelines,
                'total_credit_limit' => $this->total_credit_limit,
                'credit_utilization' => $this->credit_utilization,
                'recent_inquiries' => $this->recent_inquiries,
                'delinquent_accounts' => $this->delinquent_accounts,
                'public_records' => $this->public_records,
                'collections' => $this->collections,
                'delinquencies_30_60_90' => $this->delinquencies_30_60_90,
                'summary' => $this->summary,
                'all_accounts_analysis' => array_merge([
                    'total_accounts' => $this->all_accounts_total,
                    'open_accounts' => $this->all_accounts_open,
                    'closed_accounts' => $this->all_accounts_closed,
                    'newest_open_date' => $this->all_accounts_newest_open_date,
                    'oldest_open_date' => $this->all_accounts_oldest_open_date,
                ], $this->all_accounts_analysis ?? []),
                'open_accounts_analysis' => array_merge([
                    'monthly_payments' => $this->open_accounts_monthly_payments,
                    'balance' => $this->open_accounts_balance,
                    'credit_limit' => $this->open_accounts_credit_limit,
                    'available_credit' => $this->open_accounts_available_credit,
                    'utilization_ratio' => $this->open_accounts_utilization_ratio,
                ], $this->open_accounts_analysis ?? []),
            ],
        ];
    }
}
