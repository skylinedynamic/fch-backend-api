<?php

namespace Tests\Integration\Application;

use App\Models\Borrower\FundingApplication;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/**
 * The migration that puts funding_applications.partner_id back to the
 * borrower's partner (findings O17).
 *
 * Defect: partner-side saves stamped the application with the caller's
 * partner, so an application the host's team prepared for a partner's
 * borrower sat in the host's book.
 */
class FundingApplicationPartnerBackfillTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithPortalUsers;

    private function application(int $borrowerId, ?int $partnerId): int
    {
        return DB::table('funding_applications')->insertGetId([
            'application_number' => 'FUND-' . uniqid(),
            'borrower_id' => $borrowerId,
            'partner_id' => $partnerId,
            'status' => FundingApplication::ON_HOLD,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    #[Test]
    public function every_application_ends_up_with_its_borrowers_partner(): void
    {
        $host = $this->makeHostPartner();
        $partner = $this->makeWhiteLabelPartner();
        $borrower = $this->makeBorrower($partner);
        $hostBorrower = $this->makeBorrower($host);

        $moved = $this->application($borrower->id, $host->id);
        $missing = $this->application($borrower->id, null);
        $right = $this->application($borrower->id, $partner->id);
        $hostsOwn = $this->application($hostBorrower->id, $host->id);

        $migration = require database_path('migrations/2026_10_13_000000_backfill_funding_application_partner_from_borrower.php');
        $migration->up();
        $migration->up(); // a second run changes nothing

        $partnerOf = fn (int $id) => (int) DB::table('funding_applications')->where('id', $id)->value('partner_id');

        $this->assertSame($partner->id, $partnerOf($moved));
        $this->assertSame($partner->id, $partnerOf($missing));
        $this->assertSame($partner->id, $partnerOf($right));
        $this->assertSame($host->id, $partnerOf($hostsOwn));
    }
}
