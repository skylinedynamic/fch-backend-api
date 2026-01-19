<?php

namespace App\Services\Borrower\FundApplication;

use App\Models\Borrower\Borrower;
use App\Models\User\User;

use App\Models\Borrower\FundingApplication;

class FundingApplicationService
{
    /**
     * Get Business Info for the authenticated user.
     *
     * @param int $userId
     * @return mixed
     */
    public function getBorrowerInfo()
    {
        return Borrower::where('id', auth()->user()->entity_id)->first();
    }

    public function getFundingInfo()
    {
        $fundingInfo = FundingApplication::where('borrower_id', auth()->user()->entity_id)
                ->with('borrower')
                ->where('status', FundingApplication::ON_HOLD)
                ->latest()
                ->first();

        if($fundingInfo){
            return $fundingInfo;
        }else{
            return Borrower::where('id', auth()->user()->entity_id)->first();
        }

    }


    /**
     * Save Funding Application data.
     *
     * @param array $data
     * @return mixed
     */
    public function saveFundingApplication(array $data, $status = null)
    {
        $borrowerId = auth()->user()->entity_id;
        
        $data['status'] = $status ?? FundingApplication::ON_HOLD;
        $data['borrower_id'] = $borrowerId;

        return FundingApplication::updateOrCreate(
            ['id' => $data['id']],
            $data
        );
    }
}
