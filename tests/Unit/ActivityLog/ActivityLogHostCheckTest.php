<?php

namespace Tests\Unit\ActivityLog;

use App\Models\ActivityLog\ActivityLog;
use App\Models\Partner\Partner;
use App\Models\User\User;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * ActivityLog::isHostUser() is the single gate deciding who sees Internal-level
 * activities and who may reclassify any activity's visibility. It must key off
 * user_type, never off the mere presence of a `partner` relation — every user,
 * including Borrowers, carries a partner_id recording who originated them, so
 * `isset($user->partner)` alone is true for borrowers too. Kept in Unit (no DB,
 * no HTTP) by faking relations with setRelation() instead of persisting rows.
 */
class ActivityLogHostCheckTest extends TestCase
{
    private function userType(string $userType, ?Partner $partner = null): User
    {
        $user = new User(['user_type' => $userType]);

        if ($partner) {
            $user->setRelation('partner', $partner);
        }

        return $user;
    }

    #[Test]
    public function admin_and_employee_are_always_host(): void
    {
        $this->assertTrue(ActivityLog::isHostUser($this->userType(User::ADMIN)));
        $this->assertTrue(ActivityLog::isHostUser($this->userType(User::EMPLOYEE)));
    }

    #[Test]
    public function a_partner_user_is_host_only_when_their_partner_record_is_flagged_is_host(): void
    {
        $hostPartner = new Partner(['is_host' => true]);
        $regularPartner = new Partner(['is_host' => false]);

        $this->assertTrue(ActivityLog::isHostUser($this->userType(User::PARTNER, $hostPartner)));
        $this->assertFalse(ActivityLog::isHostUser($this->userType(User::PARTNER, $regularPartner)));
    }

    #[Test]
    public function a_partner_user_with_no_partner_relation_loaded_is_not_host(): void
    {
        $this->assertFalse(ActivityLog::isHostUser($this->userType(User::PARTNER)));
    }

    #[Test]
    public function a_borrower_is_never_host_even_when_their_originating_partner_is_the_host_partner(): void
    {
        // Regression: a Borrower's partner_id records who referred them, not a
        // role. Before the fix, isset($user->partner) alone made isHostUser()
        // (and the visibility scope) misclassify such a borrower as host staff.
        $hostPartner = new Partner(['is_host' => true]);

        $this->assertFalse(ActivityLog::isHostUser($this->userType(User::BORROWER, $hostPartner)));
    }

    #[Test]
    public function no_user_is_never_host(): void
    {
        $this->assertFalse(ActivityLog::isHostUser(null));
    }

    #[Test]
    public function can_change_visibility_by_matches_is_host_user(): void
    {
        $log = new ActivityLog();

        $this->assertTrue($log->canChangeVisibilityBy($this->userType(User::ADMIN)));
        $this->assertFalse($log->canChangeVisibilityBy($this->userType(User::PARTNER)));
        $this->assertFalse($log->canChangeVisibilityBy($this->userType(User::BORROWER)));
        $this->assertFalse($log->canChangeVisibilityBy(null));
    }
}
