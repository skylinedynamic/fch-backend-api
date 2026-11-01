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
     * A partner's team (User::teamOf) as id, name, email, phone and
     * is_inactive - the caller's own, or for the host, the partner it names.
     *
     * A member is inactive when their login is not active, or when they are an
     * employee set to Inactive or Suspended (R2 BUG-051 - that job status does
     * not change the login's status). The assign pickers ask for $activeOnly:
     * inactive members are left out, except $keepIds (whoever is already
     * assigned, so the field still shows them, flagged). List filters ask for
     * everyone.
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

        $inactiveEmployeeIds = Employee::where('partner_id', $partner->id)
            ->whereIn('job_status', [Employee::STATUS_INACTIVE, Employee::STATUS_SUSPENDED])
            ->pluck('id')
            ->all();

        return User::query()
            ->teamOf($partner)
            ->select('id', 'name', 'email', 'phone_number', 'status', 'entity_type', 'entity_id')
            ->orderBy('name', 'asc')
            ->get()
            ->map(fn (User $member) => [
                'id' => $member->id,
                'name' => $member->name,
                'email' => $member->email,
                'phone_number' => $member->phone_number,
                'is_inactive' => $member->status !== User::STATUS['active']
                    || ($member->entity_type === Employee::class
                        && in_array((int) $member->entity_id, $inactiveEmployeeIds, true)),
            ])
            ->filter(fn (array $member) => !$activeOnly
                || !$member['is_inactive']
                || in_array($member['id'], $keepIds, true))
            ->values();
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
