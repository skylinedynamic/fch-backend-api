<?php

namespace App\Services\Borrower\Auth;

use App\Models\Borrower\Borrower;
use App\Models\Borrower\BorrowerSignupId;
use App\Models\Employee\Employee;
use App\Models\User\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class BorrowerAuthService
{
    /**
     * Register a new borrower user and return user with token
     */
    public function registerBorrower(array $data): array
    {
        DB::beginTransaction();
        try {
            $signup = BorrowerSignupId::where('signup_id', $data['signup_id'])->first();
              
            if (!$signup) {
                DB::rollBack();
                Log::error(__('borrower.signup_id_not_found'));
                return [
                    'status' => false,
                    'message' => __('borrower.signup_id_not_found'),
                ];
            }

            $borrower = Borrower::create([
                'signup_id' => $signup->signup_id,
                'partner_id' => $signup->partner_id,
                'business_email' => $data['email'],
                'state' => $data['state'],
                'country' => $data['country'],
                'business_phone' => $data['country_code'] . $data['phone_number'],
            ]);

             $user = User::create([
                'name' => $data['first_name']. ' ' .$data['last_name'],
                'email' => $data['email'],
                'phone_number' => $data['country_code'] . $data['phone_number'],
                'password' => Hash::make($data['password']),
                'user_type' => User::BORROWER,
                'status' => User::STATUS['active'],
                'entity_type' => Borrower::class,
                'entity_id' => $borrower->id,
            ]);

            $signup->delete();

            $token = $user->createToken('BorrowerApp')->accessToken;

            DB::commit();

            return [
                'status' => true,
                'message' => 'Borrower registered successfully',
                'token' => $token,
                'user' => $user,
            ];
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('BorrowerAuthService registerBorrower error: ' . $e->getMessage());
            return [
                'status' => false,
                'message' => 'Registration failed. Please try again.',
            ];
        }
    }
    /**
     * Validate signup ID exists
     */
    public function validateSignupId(string $signupId): bool
    {
        return DB::table('signup_ids')
            ->where('signup_id', $signupId)
            ->exists();
    }

    /**
     * Login user and generate token
     */
    public function login(string $email, string $password): ?array
    {
        $user = User::where('email', $email)
            ->where('user_type', User::BORROWER)
            ->first();

        if (!$user || !Hash::check($password, $user->password)) {
            return null;
        }

        // Generate access token
        $token = $user->createToken('BorrowerApp')->accessToken;

        return [
            'user' => $user,
            'token' => $token,
        ];
    }
}