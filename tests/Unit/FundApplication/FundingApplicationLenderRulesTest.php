<?php

namespace Tests\Unit\FundApplication;

use App\Models\Borrower\Borrower;
use App\Models\Borrower\FundingApplication;
use App\Models\FundingRequest\FundingRequest;
use App\Models\User\User;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The lender and edit-lock rules on FundingApplication, without a database
 * (decisions 2026-11-03, F171-F174):
 *  - the 11 editable statuses (config `statuses.editable`) are the only edit rule;
 *  - every other status needs a lender before it is set;
 *  - in an editable status the lender may change; past them it is fixed once assigned;
 *  - lenders are matched on the funding request's type, else the application's
 *    Preferred + Additional Funding Types.
 */
#[CoversClass(FundingApplication::class)]
class FundingApplicationLenderRulesTest extends TestCase
{
    private function application(array $attributes = []): FundingApplication
    {
        $application = new FundingApplication;
        $application->forceFill(array_merge(['status' => FundingApplication::DRAFT, 'funding_request_id' => null], $attributes));

        return $application;
    }

    // -- Which statuses need a lender ---------------------------------------------

    #[Test]
    public function no_editable_status_needs_a_lender(): void
    {
        foreach (FundingApplication::editableStatuses() as $status) {
            $this->assertFalse(FundingApplication::statusRequiresLender($status), $status);
        }
    }

    #[Test]
    public function every_other_status_needs_a_lender(): void
    {
        $others = array_diff(FundingApplication::STATUS, FundingApplication::editableStatuses());

        $this->assertNotEmpty($others);
        foreach ($others as $status) {
            $this->assertTrue(FundingApplication::statusRequiresLender($status), $status);
        }
        $this->assertEqualsCanonicalizing(array_values($others), FundingApplication::lenderRequiredStatuses());
    }

    #[Test]
    public function the_editable_list_is_the_eleven_statuses(): void
    {
        $this->assertCount(11, FundingApplication::editableStatuses());
        $this->assertContains(FundingApplication::APPROVED_FINAL, FundingApplication::editableStatuses());
        $this->assertNotContains(FundingApplication::MATCHED_TO_LENDER, FundingApplication::editableStatuses());
    }

    // -- When the lender may be assigned or changed --------------------------------

    #[Test]
    public function in_an_editable_status_the_lender_may_always_change(): void
    {
        $this->assertTrue($this->application(['status' => FundingApplication::APPROVED_FINAL])->canAssignLender());
        $this->assertTrue($this->application(['status' => FundingApplication::APPROVED_FINAL, 'lender_ids' => [7]])->canAssignLender());
    }

    #[Test]
    public function past_the_editable_statuses_only_an_application_with_no_lender_may_get_one(): void
    {
        $this->assertTrue($this->application(['status' => FundingApplication::OFFER_ISSUED])->canAssignLender());
        $this->assertFalse($this->application(['status' => FundingApplication::OFFER_ISSUED, 'lender_ids' => [7]])->canAssignLender());
    }

    #[Test]
    public function the_assigned_lender_is_the_applications_own_without_a_funding_request(): void
    {
        $application = $this->application(['lender_ids' => ['7']]);

        $this->assertSame([7], $application->assignedLenderIds());
        $this->assertTrue($application->hasAssignedLender());
        $this->assertSame([], $this->application()->assignedLenderIds());
        $this->assertFalse($this->application()->hasAssignedLender());
    }

    #[Test]
    public function the_assigned_lender_is_the_requests_with_a_funding_request(): void
    {
        $request = (new FundingRequest)->forceFill(['lender_ids' => [9], 'funding_type' => 'Term Loan']);
        $application = $this->application(['funding_request_id' => 3, 'lender_ids' => [7]]);
        $application->setRelation('fundingRequest', $request);

        $this->assertSame([9], $application->assignedLenderIds());
    }

    // -- What the lenders are matched on --------------------------------------------

    #[Test]
    public function an_application_without_a_request_is_matched_on_its_own_funding_types(): void
    {
        $application = $this->application([
            'preferred_funding_type' => 'Term Loan',
            'additional_funding_types' => ['Business Line of Credit', ''],
        ]);

        $this->assertSame(['Term Loan', 'Business Line of Credit'], $application->lenderCategories());
        $this->assertSame([], $this->application()->lenderCategories());
    }

    #[Test]
    public function an_application_with_a_request_is_matched_on_the_requests_type(): void
    {
        $application = $this->application(['funding_request_id' => 3, 'preferred_funding_type' => 'Term Loan']);
        $application->setRelation('fundingRequest', (new FundingRequest)->forceFill(['funding_type' => 'Equipment Financing']));

        $this->assertSame(['Equipment Financing'], $application->lenderCategories());
    }

    // -- Edit lock ------------------------------------------------------------------

    #[Test]
    public function editable_is_the_status_list_for_everyone_the_host_included(): void
    {
        $locked = $this->application(['status' => FundingApplication::CLOSED_COMPLETED]);
        $open = $this->application(['status' => FundingApplication::PENDING]);
        $host = $this->user(User::ADMIN, null);

        $this->assertFalse($locked->isEditableBy($host));
        $this->assertFalse($locked->isEditableBy(null));
        $this->assertTrue($open->isEditableBy($host));
    }

    /** @return array<string, array{?string, ?string, string}> */
    public static function viewers(): array
    {
        return [
            'nobody signed in' => [null, null, 'application.funding_application_locked'],
            'a borrower login' => [User::BORROWER, Borrower::class, 'application.funding_application_locked'],
            'a host or partner login' => [User::ADMIN, null, 'application.funding_application_locked_staff'],
        ];
    }

    #[Test]
    #[DataProvider('viewers')]
    public function the_locked_reason_is_worded_for_who_is_asking(?string $userType, ?string $entityType, string $key): void
    {
        $application = $this->application(['status' => FundingApplication::FUNDED]);
        $user = $userType === null ? null : $this->user($userType, $entityType);

        $this->assertSame(__($key, ['status' => FundingApplication::FUNDED]), $application->lockedReasonFor($user));
        $this->assertSame(
            __($key, ['status' => FundingApplication::FUNDED]),
            $application->editingState($user)['locked_reason']
        );
    }

    #[Test]
    public function an_editable_application_has_no_locked_reason(): void
    {
        $this->assertNull($this->application(['status' => FundingApplication::PENDING])->editingState(null)['locked_reason']);
    }

    private function user(string $userType, ?string $entityType): User
    {
        return (new User)->forceFill(['user_type' => $userType, 'entity_type' => $entityType]);
    }
}
