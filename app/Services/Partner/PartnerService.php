<?php

namespace App\Services\Partner;

use App\Models\Company\Company;
use Illuminate\Support\Facades\Auth;

class PartnerService
{
    /**
     * Field mapping from request data to company model attributes.
     */
    private array $fieldMapping = [
        'company_name'   => 'company_name',
        'website'        => 'company_website',
        'city'           => 'city',
        'state'          => 'state',
        'zip_code'       => 'postal_code',
        'country'        => 'country',
        'tax_id'         => 'tax_identification_number',
    ];

    /**
     * Create a new partner/company.
     */
    public function store(array $data): Company
    {
        $companyData = $this->mapRequestToCompanyData($data);
        $companyData['user_id'] = Auth::id();
        $companyData['status'] = Company::STATUS_PENDING;

        return Company::create($companyData);
    }

    /**
     * Update an existing partner/company.
     */
    public function update(Company $company, array $data): Company
    {
        $updateData = $this->mapRequestToCompanyData($data);
        
        $company->update($updateData);
        
        return $company->fresh();
    }

    /**
     * Find a partner/company by ID.
     */
    public function findById(int $id): ?Company
    {
        return Company::find($id);
    }

    /**
     * Map request data to company model attributes.
     */
    private function mapRequestToCompanyData(array $data): array
    {
        $companyData = [];

        // Map standard fields
        foreach ($this->fieldMapping as $requestField => $companyField) {
            if (array_key_exists($requestField, $data)) {
                $companyData[$companyField] = $data[$requestField];
            }
        }

        // Handle partner_name in additional_data
        if (array_key_exists('partner_name', $data)) {
            $companyData['additional_data'] = [
                'partner_name' => $data['partner_name'],
            ];
        }

        return $companyData;
    }
}