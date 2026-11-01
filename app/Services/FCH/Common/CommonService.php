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
     * A partner's team (User::teamOf) as id, name, email and phone - the
     * caller's own, or for the host, the partner it names.
     *
     * The assign pickers ask for $activeOnly: inactive members are left out,
     * except $keepIds (whoever is already assigned, so the field still shows
     * them). List filters ask for everyone.
     *
     * @param  int[]  $keepIds
     */
    public function getAllUsers(?int $partnerId = null, bool $activeOnly = false, array $keepIds = [])
    {
        $user = Auth::user();

        // The host may name another partner - to pick the owner of that
        // partner's lead from its own team; anyone else gets their own team.
        $partner = $partnerId && $user->partner->is_host
            ? Partner::find($partnerId)
            : $user->partner;

        if (!$partner) {
            return collect();
        }

        $users = User::query()
            ->teamOf($partner)
            ->when($activeOnly, fn ($query) => $query->where(function ($query) use ($keepIds) {
                $query->where('status', User::STATUS['active'])
                    ->when($keepIds, fn ($query) => $query->orWhereIn('id', $keepIds));
            }))
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
            $partner = $user->partner;
            $count = $partner->usedUserSeats();
            $seatPlan = $partner->userSeatPlan();
            $perSeatPrice = $seatPlan ? (float) $seatPlan->total_amount : 49.00;
            $userSeats = $partner->userSeatLimit();

            return [
                'count' => $count,
                'seat_limit' => $userSeats,
                'per_user_price' => $perSeatPrice,
            ];
        }

        return ['count' => null];
    }
}
