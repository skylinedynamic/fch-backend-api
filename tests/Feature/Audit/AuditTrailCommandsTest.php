<?php

namespace Tests\Feature\Audit;

use App\Models\Audit\FundingApplicationAuditEvent;
use App\Models\Borrower\FundingApplication;
use App\Services\Audit\FundingApplicationAuditTrail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/**
 * The audit trail's commands - verify, the daily seal, purging the old
 * download copies - and the import of the old download history.
 */
class AuditTrailCommandsTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithPortalUsers;

    private int $applicationId;
    private int $partnerId;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        $partner = $this->makeWhiteLabelPartner();
        $this->partnerId = $partner->id;
        $this->applicationId = DB::table('funding_applications')->insertGetId([
            'application_number' => 'FUND-0000202',
            'borrower_id' => $this->makeBorrower($partner)->id,
            'partner_id' => $partner->id,
            'status' => FundingApplication::SUBMITTED,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function append(string $event = FundingApplicationAuditEvent::EVENT_UPDATED): FundingApplicationAuditEvent
    {
        return app(FundingApplicationAuditTrail::class)->append([
            'funding_application_id' => $this->applicationId,
            'partner_id' => $this->partnerId,
            'event' => $event,
            'actor_name' => 'Pat Partner',
        ]);
    }

    #[Test]
    public function verify_passes_an_intact_chain_and_fails_a_broken_one(): void
    {
        $this->append();
        $second = $this->append();
        $this->append();

        $this->artisan('audit:verify')->assertSuccessful();

        // An event taken out of the middle: the next one no longer follows on.
        // (The last one taken out is what the daily seal shows.)
        DB::table('funding_application_audit_events')->where('id', $second->id)->delete();

        $this->artisan('audit:verify', ['--application' => $this->applicationId])->assertFailed();
    }

    #[Test]
    public function the_seal_holds_each_applications_last_hash(): void
    {
        $this->append();
        $last = $this->append();

        $this->artisan('audit:seal')->assertSuccessful();

        $seal = json_decode(Storage::disk('public')->get('audit/seals/' . now('UTC')->format('Y-m-d') . '.json'), true);
        $this->assertSame([[
            'funding_application_id' => $this->applicationId,
            'events' => 2,
            'last_event_id' => $last->id,
            'last_hash' => $last->hash,
        ]], $seal['applications']);
    }

    #[Test]
    public function purging_deletes_the_download_copies_and_never_the_signed_ones(): void
    {
        $disk = Storage::disk('public');
        $disk->put('funding-applications/7/downloads/Funding_Application_FUND-0000077.pdf', 'copy');
        $disk->put('funding-applications/7/downloads/Funding_Documents_FUND-0000077.zip', 'copy');
        $disk->put('storage/funding-applications/7/downloads/Signature_Certificate_FUND-0000077.pdf', 'copy');
        $disk->put('funding-applications/7/signed/v1-cert/application.pdf', 'signed');
        $disk->put('funding-applications/7/signed/v1-cert/signature-certificate.pdf', 'signed');

        $this->artisan('funding-applications:purge-download-copies', ['--dry-run' => true])->assertSuccessful();
        $this->assertTrue($disk->exists('funding-applications/7/downloads/Funding_Application_FUND-0000077.pdf'));

        $this->artisan('funding-applications:purge-download-copies')->assertSuccessful();

        $this->assertFalse($disk->exists('funding-applications/7/downloads'));
        $this->assertFalse($disk->exists('storage'));
        $this->assertSame('signed', $disk->get('funding-applications/7/signed/v1-cert/application.pdf'));
        $this->assertSame('signed', $disk->get('funding-applications/7/signed/v1-cert/signature-certificate.pdf'));
    }

    /**
     * The old download history is carried over: downloads as downloads, the
     * copies kept at signing as such (they number the next signed version),
     * every one marked legacy, and never a password an old row still held.
     */
    #[Test]
    public function the_old_download_history_is_imported_and_its_table_dropped(): void
    {
        $migration = require database_path('migrations/2026_11_03_000000_create_funding_application_audit_events_table.php');
        $migration->down();

        DB::table('download_histories')->insert([
            ['funding_application_id' => $this->applicationId, 'user_name' => 'Pat Partner', 'user_type' => 'Partner', 'file_type' => 'application_pdf', 'action' => 'generated', 'application_version' => '1', 'certificate_id' => 'cert-1', 'sha256_hash' => str_repeat('a', 64), 'downloaded_at' => '2026-10-01 10:00:00', 'details' => null, 'ip_address' => '10.0.0.1'],
            ['funding_application_id' => $this->applicationId, 'user_name' => 'Pat Partner', 'user_type' => 'Partner', 'file_type' => 'application_zip', 'action' => 'downloaded_with_password', 'application_version' => '1', 'certificate_id' => 'cert-1', 'sha256_hash' => str_repeat('b', 64), 'downloaded_at' => '2026-10-02 11:00:00', 'details' => json_encode(['password' => 'Old#Secret', 'browser' => 'Chrome', 'file_path' => 'x.zip']), 'ip_address' => '10.0.0.2'],
        ]);

        $migration->up();

        $this->assertFalse(Schema::hasTable('download_histories'));
        $events = FundingApplicationAuditEvent::where('funding_application_id', $this->applicationId)->orderBy('id')->get();
        $this->assertSame([FundingApplicationAuditEvent::EVENT_SIGNED_COPY_KEPT, FundingApplicationAuditEvent::EVENT_DOWNLOADED], $events->pluck('event')->all());
        $this->assertTrue($events->every->is_legacy);
        $this->assertTrue($events[1]->context['password_protected']);
        $this->assertSame('Chrome', $events[1]->context['browser']);
        $this->assertSame('2026-10-02 11:00:00', $events[1]->occurred_at->format('Y-m-d H:i:s'));
        $this->assertStringNotContainsString('Old#Secret', json_encode($events->toArray()));
        $this->assertTrue(app(FundingApplicationAuditTrail::class)->verify($this->applicationId)['valid']);
    }
}
