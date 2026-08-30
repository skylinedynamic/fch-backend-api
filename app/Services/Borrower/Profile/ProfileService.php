<?php

namespace App\Services\Borrower\Profile;

use App\Mail\Borrower\BorrowerAccountCreatedMail;
use App\Models\Borrower\Borrower;
use App\Models\Employee\Employee;
use App\Models\Partner\Partner;
use App\Models\User\User;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

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

            // Update the user
            $userData = [
                'name' => $data['first_name'] . " " . $data['last_name'],
                'phone_number' => $data['phone_number'],
            ];

            $emailChanged = (isset($data['email']) && $user->email !== $data['email']);
            $plainPassword = null;

            if ($emailChanged) {
                $plainPassword = bin2hex(random_bytes(4)); // 8 chars
                $userData['email'] = $data['email'];
                $userData['password'] = Hash::make($plainPassword);
                Log::info('Password reset for Borrower user due to email change via Profile', ['user_id' => $user->id]);
            }

            User::where('id', $user->id)->update($userData);

            if ($emailChanged) {
                $hostPartner = Partner::where('is_host', 1)->first();
                $updatedUser = User::where('id', $user->id)->first();

                $mailData = [
                    'user' => $updatedUser,
                    'plain_password' => $plainPassword,
                    'business_info' => [
                        'company_name' => $hostPartner->company_name ?? config('app.name'),
                        'company_logo' => $hostPartner->company_logo ?? 'logo.png',
                        'email' => $hostPartner->email ?? config('mail.from.address'),
                        'phone_number' => $hostPartner->phone_number ?? '',
                        'address_line_1' => $hostPartner->address_line_1 ?? '',
                    ],
                    'is_update' => true,
                ];
                Log::info('Sending account update email to borrower due to email change (Profile)', ['email' => $updatedUser->email]);

                try {
                    Mail::to($updatedUser->email)->send(new BorrowerAccountCreatedMail($mailData, $updatedUser->partner_id));
                } catch (\Exception $e) {
                    Log::error('Borrower account update email failed', ['error' => $e->getMessage()]);
                }
            }

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
