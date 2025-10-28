<?php

namespace App\Services\Borrower\Profile;

use App\Models\Company\Company;
use App\Models\User\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class BusinessInfoService
{
    /**
     * Get business info for the authenticated user
     *
     * @param int $userId
     * @return Company|null
     */
    public function getBusinessInfo(int $userId): ?Company
    {
        return Company::where('user_id', $userId)->first();
    }

    /**
     * Update business info for the authenticated user
     *
     * @param int $userId
     * @param array $data
     * @return Company
     */
    public function updateBusinessInfo(int $userId, array $data): Company
    {
        return Company::updateOrCreate(
            ['user_id' => $userId],
                [
                'company_name' => $data['business_name'] ?? null,
                'company_ein' => $data['business_ein'] ?? null,
                'company_phone' => $data['business_phone'] ?? null,
                'address_line_1' => $data['business_address'] ?? null,
                'city' => $data['city'] ?? null,
                'state' => $data['state'] ?? null,
                'postal_code' => $data['zip_code'] ?? null,
                'company_entity_type' => $data['entity_type'] ?? null,
                'company_website' => $data['business_website'] ?? null,
                'company_type' => $data['industry_type'] ?? null,
            ]
        );
    }

}