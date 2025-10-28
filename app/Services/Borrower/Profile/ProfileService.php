<?php

namespace App\Services\Borrower\Profile;

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
                'address_line_1' => $data['home_address'],
                'city' => $data['city'],
                'state' => $data['state'],
                'postal_code' => $data['zip_code'],
                'country' => $data['country'],
                'phone_number_2' => $data['home_phone'] ?? null, 
                'phone_number' => $data['mobile_phone'],
                'date_of_birth' => $data['date_of_birth'],
            ];

            // Handle SSN - we need to get the existing SSN and update only the last 4 digits
            if (isset($data['last_4_ssn'])) {
                $updateData['ssn'] = $this->updateSsnLastFourDigits($user->ssn, $data['last_4_ssn']);
            }

            // Update the user
            $user->update($updateData);

            DB::commit();

            return $user->fresh();
        } catch (Exception $e) {
            DB::rollBack();
            throw new Exception('Failed to update profile: ' . $e->getMessage());
        }
    }

    /**
     * Update the last 4 digits of SSN while preserving the first part
     *
     * @param string|null $existingSsn
     * @param string $lastFourDigits
     * @return string
     */
    private function updateSsnLastFourDigits(?string $existingSsn, string $lastFourDigits): string
    {
        // If there's no existing SSN, we'll store only the last 4 digits
        // In a real application, you might want to handle this differently
        if (!$existingSsn) {
            return $lastFourDigits;
        }

        // If existing SSN has the format XXX-XX-XXXX, replace the last 4 digits
        if (strlen($existingSsn) >= 4) {
            return substr($existingSsn, 0, -4) . $lastFourDigits;
        }

        // Fallback: return the new last 4 digits with masked format
        return $lastFourDigits;
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