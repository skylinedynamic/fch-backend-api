<?php

namespace App\Services\FCH\Common;

use App\Models\User\User;
use App\Models\Partner\Partner;
use App\Models\Employee\Employee;

class CommonService
{
    /**
     * Get all users where entity_type is Employee or NULL.
     * Returns only id and name.
     */
    public function getAllUsers()
    {
        $users = User::query()
            ->where('partner_id', auth()->user()->partner_id)
            ->where(function ($query) {
                $query->whereNull('entity_type')
                    ->orWhere('entity_type', Partner::class);
            })
            ->select('id', 'name', 'email', 'phone_number')
            ->orderBy('name', 'asc')
            ->get();
    }

    /**
     * Get User Count
     */
    public function getUserCount()
    {
        $user = auth()->user();

        if ($user->partner && $user->partner->is_host == 0) {
            $count = User::where('partner_id', $user->partner_id)->withoutTrashed()->count();
            return [
                'count' => $count,
                'per_user_price' => config('app.per_user_price'),
            ];
        }

        return ['count' => null];
    }
}
