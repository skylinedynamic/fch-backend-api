<?php

namespace App\Services\Borrower\Profile;

use App\Models\Borrower\Borrower;
use App\Models\Business\Business;

class BusinessInfoService
{

    public function getBusinessInfo(int $userId)
    {
        $borrower = Borrower::with('business')->where('user_id', $userId)->first();
        return $borrower?->business;
    }

    /**
     * Update business info for the authenticated user
     *
     * @param int $userId
     * @param array $data
     * @return Business
     */
    public function updateBusinessInfo(int $userId, array $data): Business
    {
        $borrower = Borrower::where('user_id', $userId)->firstOrFail();

        return $borrower->business()->updateOrCreate(
            [],
            [
                'business_no'           => $data['business_no']       ?? null,
                'business_name'         => $data['business_name']     ?? null,
                'business_ein'          => $data['business_ein']      ?? null,
                'business_phone'        => $data['business_phone']    ?? null,
                'address_line_1'        => $data['business_address']  ?? null,
                'city'                  => $data['city']              ?? null,
                'state'                 => $data['state']             ?? null,
                'postal_code'           => $data['zip_code']          ?? null,
                'business_entity_type'  => $data['entity_type']       ?? null,
                'business_website'      => $data['business_website']  ?? null,
                'business_type'         => $data['industry_type']     ?? null,
            ]
        );
    }

}