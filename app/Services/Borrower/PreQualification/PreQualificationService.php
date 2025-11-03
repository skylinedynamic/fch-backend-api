<?php

namespace App\Services\Borrower\PreQualification;

use App\Models\Borrower\PreQualificationRequest;
use App\Models\Company\Company;

class PreQualificationService
{
    public function submit(int $userId, array $data): PreQualificationRequest
    {
        // Try to link company if exists
        $companyId = Company::where('user_id', $userId)->value('id');

        $payload = array_merge($data, [
            'user_id' => $userId,
            'business_id' => $companyId,
            'status' => 'pending',
        ]);

        return PreQualificationRequest::create($payload);
    }
}