<?php

namespace App\Services\Borrower\FundApplication;

use App\Models\Borrower\Borrower;
use App\Models\User\User;

class FundingApplicationService
{
    /**
     * Get Business Info for the authenticated user.
     *
     * @param int $userId
     * @return mixed
     */
    public function getBusinessInfo()
    {
        return Borrower::where('id',auth()->user()->entity_id)->first();
    }
}
