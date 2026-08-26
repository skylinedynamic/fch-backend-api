<?php

namespace App\Services\FCH\Common;

use App\Models\User\User;
use App\Models\Partner\Partner;
use App\Models\Borrower\Borrower;
use App\Models\Employee\Employee;
use App\Models\Partner\SubscriptionPlan;
use Illuminate\Support\Facades\Auth;

class CommonService
{
    /**
     * Get all users where entity_type is Employee or NULL.
     * Returns only id and name.
     */
    public function getAllUsers()
    {
        $user = Auth::user();
        $users = User::query()
            ->when($user->partner->is_host == 1, function ($query) use ($user) {
                $query->where('partner_id', $user->partner_id)
                    ->where('user_type',User::ADMIN);
            })
            ->when($user->partner->is_host == 0, function ($query) use ($user) {
                $query->where('partner_id', $user->partner_id)
                    ->where('user_type',User::PARTNER);
            })
            ->select('id', 'name', 'email', 'phone_number')
            ->orderBy('name', 'asc')
            ->get();

        return $users;
    }

    /**
     * Get User Count
     */
    public function getUserCount()
    {
        $user = Auth::user();

        if ($user->partner && $user->partner->is_host == 0) {
            $count = User::where('partner_id', $user->partner->id)
                ->where(function ($query) {
                    $query->whereIn('entity_type', [Partner::class, Employee::class])
                        ->orWhereNull('entity_type');
                })
                ->count();

            $seatPlan = SubscriptionPlan::where('group', SubscriptionPlan::SUBSCRIPTION_PLAN_USER_SEAT)
                ->where('partner_type', $user->partner->partner_type)
                ->active()
                ->first();

            $partnerSubscription = $user->partner->subscription;
            $perSeatPrice = $seatPlan ? (float) $seatPlan->total_amount : 49.00;
            
            $userSeats = $partnerSubscription ? ($partnerSubscription->user_seats ?? ($seatPlan?->total_seat_count ?? 3)) : ($seatPlan?->total_seat_count ?? 3);

            return [
                'count' => $count,
                'seat_limit' => $userSeats,
                'per_user_price' => $perSeatPrice,
            ];
        }

        return ['count' => null];
    }
}
