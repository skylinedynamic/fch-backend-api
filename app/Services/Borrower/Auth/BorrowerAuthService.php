<?php

namespace App\Services\Borrower\Auth;

use App\Models\User\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;



class BorrowerAuthService
{
    /**
     * Register a new borrower user
     */
    public function register(array $data): User
    {

        // Create the user
        $user = User::create([
            'first_name' => $data['first_name'],
            'last_name' => $data['last_name'],
            'email' => $data['email'],
            'phone_number' => $data['phone_number'],
            'state' => $data['state'],
            'password' => Hash::make($data['password']),
            'user_type' => User::USERTYPE['Borrower']
        ]);

        return $user;
    }

    /**
     * Register a new borrower user and return user with token
     */
    public function registerBorrower(array $data): array
    {
        $user = $this->register($data);

        DB::table('signup_ids')
            ->where('signup_id', $data['signup_id'])
            ->delete();

        $token = $user->createToken('BorrowerApp')->accessToken;
        
        return [
            'user' => $user,
            'token' => $token,
        ];
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
            ->where('user_type', User::USERTYPE['Borrower'])
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