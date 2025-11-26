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
                'address_line_1' => $data['home_address'],
                'city' => $data['city'],
                'state' => $data['state'],
                'postal_code' => $data['zip_code'],
                'country' => $data['country'],
                'country_code' => $data['country_code'],
                'date_of_birth' => $data['date_of_birth'],
                'home_phone' => $data['home_phone'],
                'last_4_ssn' => $data['last_4_ssn'],
            ];

            // Update the user
            Borrower::where('id', $user->entity_id)->update($updateData);
            
            User::where('id', $user->id)->update([
                'email' => $data['email'],
                'phone_number' => $data['mobile_phone'],
            ]);

            DB::commit();
            return $user->fresh();
        } catch (Exception $e) {
            DB::rollBack();
            throw new Exception('Failed to update profile: ' . $e->getMessage());
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