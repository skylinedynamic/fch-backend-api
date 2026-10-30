<?php

namespace App\Http\Resources\FCH\Borrower;

use App\Rules\Ssn;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BorrowerCreditProfileResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
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
                ], $this->open_accounts_analysis ?? [])
            ],
            // The SSN goes out masked like every other (BUG-145); the full
            // number is the logged reveal's (field credit_profile_ssn).
            'personal_info' => $this->maskedSsn($this->personal_info ?? [
                'name' => $this->borrower?->first_name . ' ' . $this->borrower?->last_name,
                'dob' => toDateOnly($this->borrower?->date_of_birth),
                'ssn' => $this->borrower?->last_4_ssn,
                'email' => $this->borrower?->email,
                'phone' => $this->borrower?->phone_number,
            ]),
            'addresses' => $this->addresses->map(function ($addr) {
                return [
                    'type' => $addr->type,
                    'street' => $addr->street,
                    'city' => $addr->city,
                    'state' => $addr->state,
                    'zip' => $addr->zip,
                    'date_reported' => $addr->date_reported,
                ];
            })->toArray(),
            'trade_accounts' => $this->trades->map(function ($trade) {
                return [
                    'company' => $trade->company,
                    'date_reported' => $trade->date_reported,
                    'status' => $trade->status,
                    'rating' => $trade->rating,
                    'account_number' => $trade->account_number,
                    'account_type' => $trade->account_type,
                    'portfolio_type' => $trade->portfolio_type,
                    'remarks' => $trade->remarks,
                    'payment_history_start_date' => $trade->payment_history_start_date,
                    'payment_frequency' => $trade->payment_frequency,
                    'responsibility' => $trade->responsibility,
                    'opened_date' => $trade->opened_date,
                    'terms' => $trade->terms,
                    'last_payment_date' => $trade->last_payment_date,
                    'balance' => $trade->balance,
                    'high_credit' => $trade->high_credit,
                    'limit' => $trade->limit,
                    'monthly_payment' => $trade->monthly_payment,
                    'actual_payment' => $trade->actual_payment,
                    'past_due' => $trade->past_due,
                    'months_reviewed' => $trade->months_reviewed,
                    'delinquencies' => $trade->delinquencies_30_60_90,
                    'max_delinquency_date' => $trade->max_delinquency_date,
                ];
            })->toArray(),
            'inquiries' => $this->inquiries->map(function ($inq) {
                return [
                    'date' => $inq->date,
                    'company' => $inq->company,
                    'industry' => $inq->industry,
                ];
            })->toArray(),
            'pdf_url' => $this->pdf_url,
            'document_id' => $this->document_id,
        ];
    }

    private function maskedSsn(array $personalInfo): array
    {
        if (array_key_exists('ssn', $personalInfo)) {
            $personalInfo['ssn'] = Ssn::mask($personalInfo['ssn']);
        }

        return $personalInfo;
    }
}
