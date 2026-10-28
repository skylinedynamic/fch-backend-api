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
     * Logins of Inactive or Suspended employees are left out, so they are not
     * offered for a new assignment (R2 BUG-061). $includeUserId keeps one of
     * them - the record's current assignee - so its name still shows.
     */
    public function getAllUsers(?int $partnerId = null, ?int $includeUserId = null)
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
            ->select('id', 'name', 'email', 'phone_number', 'entity_type', 'entity_id')
            ->orderBy('name', 'asc')
            ->get()
            ->map(fn (User $member) => [
                'id' => $member->id,
                'name' => $member->name,
                'email' => $member->email,
                'phone_number' => $member->phone_number,
                'is_inactive' => $member->entity_type === Employee::class
                    && in_array((int) $member->entity_id, $inactiveEmployeeIds, true),
            ])
            ->filter(fn (array $member) => !$member['is_inactive'] || $member['id'] === $includeUserId)
            ->values();
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
