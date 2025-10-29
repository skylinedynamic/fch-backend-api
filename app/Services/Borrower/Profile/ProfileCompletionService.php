<?php

namespace App\Services\Borrower\Profile;

use App\Models\User\User;
use App\Models\Company\Company;

class ProfileCompletionService
{
    /**
     * Check if the borrower's profile is complete (personal, business, financial required fields).
     * Returns overall status and which fields are missing per section.
     */
    public function checkCompletion(User $user): array
    {
        $personal = $this->checkPersonal($user);

        $company = Company::where('user_id', $user->id)->first();
        
        $business = $this->checkBusiness($company);
        $financial = $this->checkFinancial($company);

        $isComplete = $personal['is_complete'] && $business['is_complete'] && $financial['is_complete'];

        return [
            'is_complete' => $isComplete,
            'personal' => $personal,
            'business' => $business,
            'financial' => $financial,
        ];
    }

    /**
     * Check personal required fields against the User model.
     * Required request keys: first_name, last_name, email, home_address, city, state, zip_code, country, mobile_phone, last_4_ssn, date_of_birth
     */
    private function checkPersonal(User $user): array
    {
        $missing = [];

        $fields = [
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'email' => $user->email,
            'home_address' => $user->address_line_1,
            'city' => $user->city,
            'state' => $user->state,
            'zip_code' => $user->postal_code,
            'country' => $user->country,
            'mobile_phone' => $user->phone_number,
            'date_of_birth' => $user->date_of_birth,
        ];
        foreach ($fields as $key => $value) {
            $this->requireField($missing, $key, $value);
        }

        // last_4_ssn: consider present if ssn exists and has at least 4 characters
        $this->requireMinLength($missing, 'last_4_ssn', $user->ssn, 4);

        return [
            'is_complete' => empty($missing),
            'missing_fields' => $missing,
        ];
    }

    /**
     * Check business required fields against the Company model.
     * Required request keys: business_name, business_address, city, state, zip_code, entity_type, industry_type
     */
    private function checkBusiness(?Company $company): array
    {
        $missing = [];
        if (!$company) {
            $missing = [
                'business_name', 'business_address', 'city', 'state', 'zip_code', 'entity_type', 'industry_type'
            ];
            return [
                'is_complete' => false,
                'missing_fields' => $missing,
            ];
        }

        $fields = [
            'business_name' => $company->company_name,
            'business_address' => $company->address_line_1,
            'city' => $company->city,
            'state' => $company->state,
            'zip_code' => $company->postal_code,
            'entity_type' => $company->company_entity_type,
            'industry_type' => $company->company_type,
        ];
        foreach ($fields as $key => $value) {
            $this->requireField($missing, $key, $value);
        }

        return [
            'is_complete' => empty($missing),
            'missing_fields' => $missing,
        ];
    }

    /**
     * Check financial required fields against the Company model.
     * Required request keys: currency, years_in_business, monthly_revenue
     */
    private function checkFinancial(?Company $company): array
    {
        $missing = [];
        if (!$company) {
            $missing = ['currency', 'years_in_business', 'monthly_revenue'];
            return [
                'is_complete' => false,
                'missing_fields' => $missing,
            ];
        }

        $this->requireField($missing, 'currency', $company->currency);
        $this->requireNumericField($missing, 'years_in_business', $company->years_in_business);
        $this->requireNumericField($missing, 'monthly_revenue', $company->monthly_revenue);

        return [
            'is_complete' => empty($missing),
            'missing_fields' => $missing,
        ];
    }

    /**
     * Helper to mark a field as missing when empty.
     */
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

    private function requireNumericField(array &$missing, string $key, mixed $value): void
    {
        if ($value === null || $value === '' || !is_numeric($value)) {
            $missing[] = $key;
            return;
        }
    }

    private function requireMinLength(array &$missing, string $key, mixed $value, int $min): void
    {
        if ($value === null || mb_strlen(trim((string)$value)) < $min) {
            $missing[] = $key;
            return;
        }
    }
}