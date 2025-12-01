<?php

namespace App\Services\Borrower\Profile;

use App\Models\Borrower\Borrower;
use App\Models\User\User;
use App\Models\Company\Company;
use Illuminate\Support\Facades\Auth;

class ProfileCompletionService
{
    /**
     * Check if the borrower's profile is complete (personal, business, financial required fields).
     * Returns overall status and which fields are missing per section.
     */
    public function checkCompletion(): array
    {
        $user = Auth::user();
        $borrower = Borrower::where('id', $user->entity_id)->first();

        $personal = $this->checkPersonal($borrower);
        $business = $this->checkBusiness($borrower);
        $financial = $this->checkFinancial($borrower);

        $isComplete = $personal['is_complete'] && $business['is_complete'] && $financial['is_complete'];

        return [
            'is_complete' => $isComplete,
            'personal' => $personal,
            'business' => $business,
            'financial' => $financial,
        ];
    }

    private function checkPersonal($borrower): array
    {
        $missing = [];

        $fields = [
            'first_name' => $borrower->first_name,
            'last_name' => $borrower->last_name,
            'email' => $borrower->email,
            'phone_number' => $borrower->phone_number,
            'city' => $borrower->city,
            'state' => $borrower->state,
            'zip_code' => $borrower->postal_code,
            'country' => $borrower->country,
            'home_address' => $borrower->home_address,
            'last_4_ssn' => $borrower->last_4_ssn,  
            'date_of_birth' => $borrower->date_of_birth,
        ];
        foreach ($fields as $key => $value) {
            $this->requireField($missing, $key, $value);
        }

        return [
            'is_complete' => empty($missing),
            'missing_fields' => $missing,
        ];
    }

    private function checkBusiness($borrower): array
    {
        $missing = [];

        $fields = [
            'business_name' => $borrower->business_name,
            'business_ein' => $borrower->business_ein,
            'business_phone' => $borrower->business_phone,
            'business_address' => $borrower->business_address,
            'entity_type' => $borrower->business_entity_type,
            'industry_type' => $borrower->business_industry_type,
            'state' => $borrower->business_state,
            'city' => $borrower->business_city,
            'zip_code' => $borrower->business_postal_code,
            
        ];
        foreach ($fields as $key => $value) {
            $this->requireField($missing, $key, $value);
        }

        return [
            'is_complete' => empty($missing),
            'missing_fields' => $missing,
        ];
    }

    private function checkFinancial($borrower): array
    {
        $missing = [];

        $fields = [
            'monthly_revenue' => $borrower->monthly_revenue,
            'annual_revenue' => $borrower->annual_revenue,
            'years_in_business' => $borrower->years_in_business,
        ];

        foreach ($fields as $key => $value) {
            $this->requireField($missing, $key, $value);
        }

        return [
            'is_complete' => empty($missing),
            'missing_fields' => $missing,
        ];
    }

    private function requireField(array &$missing, string $key, mixed $value): void
    {
        if ($value === null) {
            $missing[] = $key;
            return;
        }
        if (is_string($value) && trim($value) === '') {
            $missing[] = $key;
            return;
        }
    }

}