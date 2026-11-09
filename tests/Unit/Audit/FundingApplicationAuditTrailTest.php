<?php

namespace Tests\Unit\Audit;

use App\Models\Audit\FundingApplicationAuditEvent;
use App\Models\Borrower\FundingApplicationCoBorrower;
use App\Services\Audit\FundingApplicationAuditTrail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

#[CoversClass(FundingApplicationAuditTrail::class)]
class FundingApplicationAuditTrailTest extends TestCase
{
    use RefreshDatabase;

    /**
     * MySQL's JSON column gives the keys back in its own order, not the order
     * written: the chain must still verify.
     */
    #[Test]
    public function the_chain_verifies_whatever_order_the_json_keys_come_back_in(): void
    {
        $trail = app(FundingApplicationAuditTrail::class);
        $event = $trail->append([
            'funding_application_id' => 9,
            'event' => FundingApplicationAuditEvent::EVENT_DOWNLOADED,
            'context' => ['file_type' => 'application_pdf', 'password_protected' => false, 'browser' => 'Chrome'],
            'changes' => [['field' => 'status', 'old' => 'A', 'new' => 'B']],
        ]);

        DB::table('funding_application_audit_events')->where('id', $event->id)->update([
            'context' => json_encode(['browser' => 'Chrome', 'password_protected' => false, 'file_type' => 'application_pdf']),
            'changes' => json_encode([['new' => 'B', 'old' => 'A', 'field' => 'status']]),
        ]);

        $this->assertTrue($trail->verify(9)['valid']);
    }

    #[Test]
    public function masked_fields_keep_only_their_last_four(): void
    {
        $coBorrower = new FundingApplicationCoBorrower();
        $coBorrower->setRawAttributes(['ssn' => '111-22-3333', 'drivers_license_number' => 'D1234567'], true);
        $coBorrower->forceFill(['ssn' => '444556666', 'drivers_license_number' => 'X99887766']);
        $coBorrower->syncChanges();

        $changes = collect(app(FundingApplicationAuditTrail::class)->changesOf($coBorrower))->keyBy('field');

        $this->assertSame('***-**-3333', $changes['ssn']['old']);
        $this->assertSame('***-**-6666', $changes['ssn']['new']);
        $this->assertSame('****4567', $changes['drivers_license_number']['old']);
        $this->assertSame('****7766', $changes['drivers_license_number']['new']);
    }

    #[Test]
    public function an_event_that_cannot_be_written_never_fails_the_change(): void
    {
        $this->assertNull(app(FundingApplicationAuditTrail::class)->record(999999, FundingApplicationAuditEvent::EVENT_UPDATED));
    }
}
