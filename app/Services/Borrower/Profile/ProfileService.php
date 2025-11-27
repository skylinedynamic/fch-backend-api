<?php

namespace App\Services\Borrower\Profile;

use App\Models\Borrower\Borrower;
use App\Models\Employee\Employee;
use App\Models\User\User;
use Illuminate\Support\Facades\DB;
use Exception;

class ProfileService
{
    /**
     * Update borrower profile
     *
     * @param User $user
     * @param array $data
     * @return User
     * @throws Exception
     */
    public function updateProfile(User $user, array $data): User
    {
        try {
            DB::beginTransaction();

            // Map the request fields to database columns
            $updateData = [
                'first_name' => $data['first_name'],
                'last_name' => $data['last_name'],
                'email' => $data['email'],
                'phone_number' => $data['phone_number'],
                'country' => $data['country'],
                'country_code' => $data['country_code'],
                'state' => $data['state'],
                'city' => $data['city'],
                'postal_code' => $data['zip_code'],
                'home_address' => $data['home_address'],
                'home_phone' => $data['home_phone'],
                'date_of_birth' => $data['date_of_birth'],
                'last_4_ssn' => $data['last_4_ssn'],
            ];

            // Update the user
            Borrower::where('id', $user->entity_id)->update($updateData);
            
            DB::commit();
            return $user->fresh();
        } catch (Exception $e) {
            DB::rollBack();
            throw new Exception(__('borrower.failed_to_update_profile: ' . $e->getMessage()));
        }
    }


    /**
     * Get borrower profile
     *
     * @param User $user
     * @return User
     */
    public function getProfile(User $user): User
    {
        return $user->fresh();
    }

}