<?php

namespace App\Services\Borrower\Profile;

use App\Models\Borrower\Borrower;
use App\Models\User\User;

class BusinessInfoService
{
    public function getBusinessInfo(int $userId)
    {
        $borrower = User::with("entity")->where("id", $userId)->first();
        return $borrower?->entity;
    }

    /**
     * Update business info for the authenticated user
     *
     * @param int $userId
     * @param array $data
     */
    public function updateBusinessInfo(int $userId, array $data)
    {
        $user = User::with("entity")->where("id", $userId)->first();
        $borrower = $user?->entity;

        if (!$borrower) {
            throw new \Exception("Borrower record not found.");
        }

        $borrower->update([
            "business_name"          => $data["business_name"] ?? null,
            "business_ein"           => $data["business_ein"] ?? null,
            "business_phone"         => $data["business_phone"] ?? null,
            "business_email"         => $data["business_email"] ?? null,
            "duns_number"            => $data["duns_number"] ?? null,
            "business_address"       => $data["business_address"] ?? null,
            "business_country"       => $data["business_country"] ?? null,
            "business_country_code"  => $data["business_country_code"] ?? null,
            "business_city"          => $data["business_city"] ?? null,
            "business_state"         => $data["business_state"] ?? null,
            "business_postal_code"   => $data["business_zip_code"] ?? null,
            "business_entity_type"   => $data["business_entity_type"] ?? null,
            "business_website"       => $data["business_website"] ?? null,
            "business_industry_type" => $data["business_industry_type"] ?? null,
            "business_start_date"    => $data["business_start_date"] ?? null,
            "business_credit_score"  => $data["business_credit_score"] ?? null,
        ]);

        return $borrower;
    }
}
