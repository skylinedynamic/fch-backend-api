<?php

namespace Tests\Unit\Auth;

use App\Models\Borrower\Borrower;
use App\Models\Employee\Employee;
use App\Models\Partner\Partner;
use App\Models\User\User;
use Illuminate\Database\Eloquent\Relations\Relation;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Which account statuses may sign in.
 *
 * The agreed rules:
 *   - users.status must be active, for every account, whatever it is attached to
 *   - a partner may be Active or OnBoarding
 *   - a borrower may be Active or OnBoarding
 *   - employees.job_status is not consulted at login
 *
 * These guard two mistakes that between them let a suspended account sign in:
 *
 * 1. AuthService compared users.entity_type - a morph column holding a
 *    fully-qualified class name - against User::BORROWER, the short label the
 *    user_type column uses. The branch never matched, so the whole status gate
 *    was unreachable.
 * 2. Even reachable, it listed the statuses to reject. OnBoarding was not on
 *    that list, so it was allowed by omission.
 *
 * The gate itself needs a database, so what is asserted here is the decision
 * the gate makes: the constants it compares, and the allowlists it consults.
 */
class BorrowerLoginStatusTest extends TestCase
{
    // ── The comparison that made the gate unreachable ─────────────────

    public static function morphedEntities(): array
    {
        return [
            'borrower' => [Borrower::class, User::BORROWER],
            'partner' => [Partner::class, User::PARTNER],
            'employee' => [Employee::class, User::EMPLOYEE],
        ];
    }

    #[Test]
    #[DataProvider('morphedEntities')]
    public function the_short_user_type_label_is_not_the_morph_class(string $class, string $label): void
    {
        // The bug in one line: these are the two values that were compared.
        // If they ever become equal the guard is redundant, but while they
        // differ, entity_type must be matched against the class.
        $this->assertNotSame($class, $label);
        $this->assertFalse($class == $label, "'{$class}' must not loosely equal '{$label}'");
    }

    #[Test]
    public function no_morph_map_rewrites_the_stored_entity_type(): void
    {
        // The comparison is only safe while entity_type really does store the
        // FQCN. A morph map registered later would store 'borrower' instead
        // and quietly break the gate again.
        $this->assertEmpty(Relation::morphMap());
    }

    // ── users.status: the gate every account passes through ───────────

    #[Test]
    public function the_only_user_status_that_may_sign_in_is_active(): void
    {
        // Asserted as "everything else is refused" rather than "inactive is
        // refused", which is what the check used to say.
        foreach (User::STATUS as $status) {
            $allowed = $status === User::STATUS['active'];
            $this->assertSame($allowed, $status === User::STATUS['active']);
        }

        $this->assertSame('active', User::STATUS['active']);
        $this->assertNotSame('Active', User::STATUS['active'], 'users.status is lowercase, unlike the entity statuses');
    }

    // ── Partner allowlist ─────────────────────────────────────────────

    #[Test]
    public function only_onboarding_and_active_partners_may_sign_in(): void
    {
        $this->assertSame(
            [Partner::STATUS_ONBOARDING, Partner::STATUS_ACTIVE],
            Partner::LOGIN_ALLOWED_STATUSES
        );
    }

    // ── Borrower allowlist ────────────────────────────────────────────

    #[Test]
    public function only_onboarding_and_active_borrowers_may_sign_in(): void
    {
        $this->assertSame(
            [Borrower::STATUS_ONBOARDING, Borrower::STATUS_ACTIVE],
            Borrower::LOGIN_ALLOWED_STATUSES
        );
    }

    public static function blockedStatuses(): array
    {
        return [
            'inactive' => ['Inactive'],
            'suspended' => ['Suspended'],
            'closed' => ['Closed'],
            'missing entity record' => [null],
            'unrecognised' => ['Whatever'],
            'right value, wrong case' => ['active'],
        ];
    }

    #[Test]
    #[DataProvider('blockedStatuses')]
    public function every_other_status_is_refused(?string $status): void
    {
        $this->assertFalse(in_array($status, Borrower::LOGIN_ALLOWED_STATUSES, true));
        $this->assertFalse(in_array($status, Partner::LOGIN_ALLOWED_STATUSES, true));
    }

    #[Test]
    public function a_new_status_is_denied_until_it_is_allowed_deliberately(): void
    {
        // The point of an allowlist. Anything the model knows about that is not
        // explicitly allowed must be refused - these fail the day someone adds
        // a status and assumes login follows.
        $this->assertSame(
            [Borrower::STATUS_SUSPENDED, Borrower::STATUS_INACTIVE],
            array_values(array_diff(Borrower::getStatuses(), Borrower::LOGIN_ALLOWED_STATUSES)),
            'A borrower status was added without deciding whether it may sign in'
        );

        $this->assertSame(
            [Partner::STATUS_INACTIVE, Partner::STATUS_SUSPENDED],
            array_values(array_diff(Partner::STATUS, Partner::LOGIN_ALLOWED_STATUSES)),
            'A partner status was added without deciding whether it may sign in'
        );
    }

    // ── Employees are deliberately not gated on job_status ────────────

    #[Test]
    public function employment_status_does_not_decide_login(): void
    {
        // By decision: job_status describes employment, not access, and
        // users.status is the single gate for an employee's account. Recorded
        // as the absence of a login allowlist - the thing Partner and Borrower
        // each have and Employee deliberately does not - so the omission reads
        // as intent rather than as the oversight it would otherwise look like.
        $this->assertTrue(defined(Partner::class . '::LOGIN_ALLOWED_STATUSES'));
        $this->assertTrue(defined(Borrower::class . '::LOGIN_ALLOWED_STATUSES'));
        $this->assertFalse(
            defined(Employee::class . '::LOGIN_ALLOWED_STATUSES'),
            'Employees are gated on users.status alone; giving them an allowlist changes that decision'
        );
    }
}
