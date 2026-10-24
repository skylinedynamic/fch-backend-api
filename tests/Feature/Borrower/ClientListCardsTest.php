<?php

namespace Tests\Feature\Borrower;

use App\Http\Controllers\Api\V1\FCH\Common\Borrower\BorrowerController;
use App\Models\Borrower\Borrower;
use App\Models\Partner\Partner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/**
 * The Clients list cards (statistics on POST /common/borrowers/fetch-all-borrowers).
 * The host sees how its book splits between its own clients and its partners';
 * a partner sees its own clients by status. Deleted and archived clients never
 * count, and the cards ignore the table's search and filters.
 */
#[CoversClass(BorrowerController::class)]
class ClientListCardsTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithPortalUsers;

    private Partner $host;
    private Partner $partner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->host = $this->makeHostPartner();
        $this->partner = $this->makeWhiteLabelPartner();
        $other = $this->makeCertifiedPartner();

        // Host: 2 active, 1 suspended.
        $this->makeBorrower($this->host, ['status' => Borrower::STATUS_ACTIVE]);
        $this->makeBorrower($this->host, ['status' => Borrower::STATUS_ACTIVE]);
        $this->makeBorrower($this->host, ['status' => Borrower::STATUS_SUSPENDED]);
        // Partner: 1 active, 1 inactive, 2 suspended.
        $this->makeBorrower($this->partner, ['status' => Borrower::STATUS_ACTIVE]);
        $this->makeBorrower($this->partner, ['status' => Borrower::STATUS_INACTIVE]);
        $this->makeBorrower($this->partner, ['status' => Borrower::STATUS_SUSPENDED]);
        $this->makeBorrower($this->partner, ['status' => Borrower::STATUS_SUSPENDED]);
        // Another partner: 1 active.
        $this->makeBorrower($other, ['status' => Borrower::STATUS_ACTIVE]);
        // Never counted.
        $this->makeBorrower($this->partner, ['status' => Borrower::STATUS_ACTIVE])->delete();
        $this->makeBorrower($this->host, ['status' => Borrower::STATUS_ACTIVE])->forceFill(['archived_at' => now()])->save();
    }

    private function cards(array $filters = []): array
    {
        return $this->postJson('/api/v1/common/borrowers/fetch-all-borrowers', $filters)->assertOk()->json('statistics');
    }

    #[Test]
    public function the_host_sees_its_own_clients_and_its_partners_clients(): void
    {
        $this->actingAsUser($this->makePartnerAdmin($this->host));

        $cards = $this->cards();

        $this->assertSame(8, $cards['total_borrowers']);
        $this->assertSame(3, $cards['host_borrowers']);
        $this->assertSame(5, $cards['partner_borrowers']);
        $this->assertSame(4, $cards['active_borrowers']);
    }

    #[Test]
    public function a_partner_sees_its_own_clients_by_status(): void
    {
        $this->actingAsUser($this->makePartnerAdmin($this->partner));

        $cards = $this->cards();

        $this->assertSame(4, $cards['total_borrowers']);
        $this->assertSame(1, $cards['active_borrowers']);
        $this->assertSame(1, $cards['inactive_borrowers']);
        $this->assertSame(2, $cards['suspended_borrowers']);
    }

    #[Test]
    public function the_cards_ignore_the_tables_search(): void
    {
        $this->actingAsUser($this->makePartnerAdmin($this->partner));

        $this->assertSame(2, $this->cards(['search' => 'nobody-matches-this'])['suspended_borrowers']);
    }
}
