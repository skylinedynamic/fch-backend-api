<?php

namespace Tests\Feature\Borrower;

use App\Http\Controllers\Api\V1\FCH\Common\Borrower\BorrowerController;
use App\Models\Borrower\Borrower;
use App\Models\Partner\Partner;
use App\Models\User\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/**
 * Who may reach a borrower's record through the partner portal's
 * /common/borrowers* endpoints. The rule is the one the borrower list has
 * always applied: the host's team sees every borrower, anyone else sees only
 * their own partner's - whatever their user_type, so staff included. A
 * borrower reaches only their own record.
 *
 * Defect: every endpoint below except the list took a bare id. getDetails()
 * scoped only user_type Partner, so staff (user_type Admin) and borrowers
 * could read and change any borrower on the platform, and delete, archive,
 * documents, stats and the credit profile were not scoped at all.
 */
#[CoversClass(BorrowerController::class)]
class BorrowerTenantIsolationTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithPortalUsers;

    private Partner $host;
    private Partner $partner;
    private Partner $otherPartner;
    private Borrower $partnersBorrower;
    private Borrower $otherPartnersBorrower;

    protected function setUp(): void
    {
        parent::setUp();

        $this->host = $this->makeHostPartner();
        $this->partner = $this->makeWhiteLabelPartner();
        $this->otherPartner = $this->makeCertifiedPartner();
        $this->partnersBorrower = $this->makeBorrower($this->partner, ['first_name' => 'Mine']);
        $this->otherPartnersBorrower = $this->makeBorrower($this->otherPartner, ['first_name' => 'Theirs']);
    }

    /** Every read that takes a borrower id: [method, url pattern]. */
    public static function readEndpoints(): array
    {
        return [
            'details' => ['GET', '/api/v1/common/borrowers/%d'],
            'business financials' => ['GET', '/api/v1/common/borrowers/%d/business-financials'],
            'business financials (alt url)' => ['GET', '/api/v1/common/borrowers/business-financials/%d'],
            'profile' => ['GET', '/api/v1/common/borrowers/get-borrower-profile/%d'],
            'credit profile' => ['GET', '/api/v1/common/borrowers/get-credit-profile/%d'],
            'documents' => ['POST', '/api/v1/common/borrowers-documents/%d'],
            'profile stats' => ['POST', '/api/v1/common/borrowers-profile-states-details-count/%d'],
        ];
    }

    private function read(string $method, string $pattern, Borrower $borrower)
    {
        return $this->json($method, sprintf($pattern, $borrower->id));
    }

    #[Test]
    #[DataProvider('readEndpoints')]
    public function a_partner_admin_reads_its_own_borrower_but_not_another_partners(string $method, string $url): void
    {
        $this->actingAsUser($this->makePartnerAdmin($this->partner));

        $this->read($method, $url, $this->partnersBorrower)->assertOk();
        $this->read($method, $url, $this->otherPartnersBorrower)->assertNotFound();
    }

    #[Test]
    #[DataProvider('readEndpoints')]
    public function a_partners_staff_user_is_held_to_the_partners_borrowers_too(string $method, string $url): void
    {
        $this->actingAsUser($this->makeStaffUser($this->partner));

        $this->read($method, $url, $this->partnersBorrower)->assertOk();
        $this->read($method, $url, $this->otherPartnersBorrower)->assertNotFound();
    }

    #[Test]
    #[DataProvider('readEndpoints')]
    public function the_hosts_team_reads_every_partners_borrowers(string $method, string $url): void
    {
        foreach ([$this->makePartnerAdmin($this->host), $this->makeStaffUser($this->host)] as $user) {
            $this->actingAsUser($user);

            $this->read($method, $url, $this->partnersBorrower)->assertOk();
            $this->read($method, $url, $this->otherPartnersBorrower)->assertOk();
        }
    }

    #[Test]
    #[DataProvider('readEndpoints')]
    public function a_borrower_cannot_read_another_borrower(string $method, string $url): void
    {
        $borrowerUser = $this->makeBorrowerUser($this->partner);
        $this->actingAsUser($borrowerUser);

        // Turned away at the partner-portal gate before the scope is reached.
        $this->read($method, $url, $this->partnersBorrower)->assertForbidden();
    }

    /**
     * Behind the gate the scope still holds on its own, so a borrower route
     * that ever passes a borrower through to these services stays safe.
     */
    #[Test]
    public function the_visibility_scope_gives_a_borrower_only_their_own_record(): void
    {
        $me = $this->makeBorrowerUser($this->host);

        $ids = Borrower::visibleTo($me)->pluck('id');

        $this->assertSame([$me->entity_id], $ids->all());
    }

    #[Test]
    public function the_visibility_scope_gives_the_host_everyone_and_a_partner_its_own(): void
    {
        $hostIds = Borrower::visibleTo($this->makeStaffUser($this->host))->pluck('id')->sort()->values();
        $partnerIds = Borrower::visibleTo($this->makeStaffUser($this->partner))->pluck('id');

        $this->assertSame([$this->partnersBorrower->id, $this->otherPartnersBorrower->id], $hostIds->all());
        $this->assertSame([$this->partnersBorrower->id], $partnerIds->all());
    }

    #[Test]
    public function a_staff_user_updating_another_partners_borrower_gets_not_found_and_changes_nothing(): void
    {
        $this->actingAsUser($this->makeStaffUser($this->partner));

        $this->postJson("/api/v1/common/borrowers/{$this->otherPartnersBorrower->id}", ['first_name' => 'Hijacked'])
            ->assertNotFound();

        $this->assertSame('Theirs', $this->otherPartnersBorrower->fresh()->first_name);
    }

    #[Test]
    public function deleting_touches_only_borrowers_the_user_can_see(): void
    {
        $this->actingAsUser($this->makePartnerAdmin($this->partner));

        $this->postJson('/api/v1/common/borrowers-multiple-delete', ['ids' => [$this->otherPartnersBorrower->id]]);

        $this->assertNotNull(Borrower::find($this->otherPartnersBorrower->id));
    }

    #[Test]
    public function a_borrower_cannot_delete_anyone(): void
    {
        $this->actingAsUser($this->makeBorrowerUser($this->partner));

        $this->postJson('/api/v1/common/borrowers-multiple-delete', ['ids' => [$this->partnersBorrower->id]]);

        $this->assertNotNull(Borrower::find($this->partnersBorrower->id));
    }

    #[Test]
    public function archiving_touches_only_borrowers_the_user_can_see(): void
    {
        $this->actingAsUser($this->makePartnerAdmin($this->partner, ['update clients']));

        $this->postJson('/api/v1/common/borrowers/archive', ['ids' => [$this->otherPartnersBorrower->id, $this->partnersBorrower->id]])
            ->assertOk()
            ->assertJsonPath('data.archived', 1);

        $this->assertNull($this->otherPartnersBorrower->fresh()->archived_at);
        $this->assertNotNull($this->partnersBorrower->fresh()->archived_at);
    }

    #[Test]
    public function the_deletable_check_reports_only_borrowers_the_user_can_see(): void
    {
        $this->actingAsUser($this->makePartnerAdmin($this->partner));

        $ids = collect($this->postJson('/api/v1/common/borrowers/check-deletable', [
            'ids' => [$this->partnersBorrower->id, $this->otherPartnersBorrower->id],
        ])->assertOk()->json('data'))->pluck('id');

        $this->assertSame([$this->partnersBorrower->id], $ids->all());
    }
}
