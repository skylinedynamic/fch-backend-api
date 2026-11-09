<?php

namespace Tests\Feature\Audit;

use App\Models\Borrower\FundingApplication;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;
use App\Models\Partner\Partner;

/**
 * audit:move-signed-copies - the copies signed before the audit folder held
 * them move from funding-applications/{id}/signed/ to audit/files/{sha256},
 * their signature records follow, and funding-applications/ goes. Nothing
 * signed is lost: a file is deleted only once its audit copy is in place and
 * matches it byte for byte.
 */
class MoveSignedCopiesToAuditTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithPortalUsers;

    private int $applicationId;

    private const APP = 'funding-applications/7/signed/v1-cert/application.pdf';
    private const CERT = 'funding-applications/7/signed/v1-cert/signature-certificate.pdf';
    private const OLD_APP = 'funding-applications/7/signed/v0-old/application.pdf';

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Storage::fake('public');
        $disk = Storage::disk('public');
        $disk->put(self::APP, '%PDF signed application');
        $disk->put(self::CERT, '%PDF certificate');
        $disk->put(self::OLD_APP, '%PDF first signing');

        $host = $this->makeHostPartner();
        $this->applicationId = DB::table('funding_applications')->insertGetId([
            'application_number' => 'FUND-0000077',
            'borrower_id' => $this->makeBorrower($host)->id,
            'partner_id' => $host->id,
            'status' => FundingApplication::SUBMITTED,
            'signature_data' => json_encode([
                'signed_at' => '2026-10-19T08:30:00+00:00',
                'sha256' => hash('sha256', '%PDF signed application'),
                'signed_application_path' => self::APP,
                'certificate_path' => self::CERT,
            ]),
            'previous_signature_data' => json_encode(['signed_application_path' => self::OLD_APP]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function signature(string $column = 'signature_data'): array
    {
        return json_decode(DB::table('funding_applications')->where('id', $this->applicationId)->value($column), true);
    }

    #[Test]
    public function a_dry_run_changes_nothing(): void
    {
        $this->artisan('audit:move-signed-copies', ['--dry-run' => true])->assertSuccessful();

        $this->assertSame(self::APP, $this->signature()['signed_application_path']);
        Storage::disk('public')->assertExists(self::APP);
        $this->assertSame([], Storage::disk('uploads')->allFiles('audit'));
    }

    #[Test]
    public function the_signed_copies_move_to_the_audit_folder_and_their_records_follow(): void
    {
        $this->artisan('audit:move-signed-copies')->assertSuccessful();

        $signature = $this->signature();
        $appHash = hash('sha256', '%PDF signed application');
        $this->assertSame("audit/files/{$appHash}.pdf", $signature['signed_application_path']);
        $this->assertSame('audit/files/' . hash('sha256', '%PDF certificate') . '.pdf', $signature['certificate_path']);
        $this->assertSame('audit/files/' . hash('sha256', '%PDF first signing') . '.pdf', $this->signature('previous_signature_data')['signed_application_path']);
        $this->assertSame('%PDF signed application', Storage::disk('uploads')->get($signature['signed_application_path']));
        // Nothing else of the record changes.
        $this->assertSame($appHash, $signature['sha256']);
        $this->assertSame('2026-10-19T08:30:00+00:00', $signature['signed_at']);

        $this->assertFalse(Storage::disk('public')->exists('funding-applications'));
    }

    #[Test]
    public function the_moved_copy_is_still_what_a_download_hands_out(): void
    {
        $this->artisan('audit:move-signed-copies')->assertSuccessful();
        $this->actingAsUser($this->makePartnerAdmin(Partner::where('is_host', true)->firstOrFail()));

        $response = $this->post("/api/v1/common/request-application/funding-application/{$this->applicationId}/download-application")->assertOk();

        $this->assertSame('%PDF signed application', $response->getContent());
    }

    #[Test]
    public function running_it_again_changes_nothing(): void
    {
        $this->artisan('audit:move-signed-copies')->assertSuccessful();
        $after = $this->signature();

        $this->artisan('audit:move-signed-copies')->assertSuccessful();

        $this->assertSame($after, $this->signature());
    }

    /** A record pointing at a file that is not there is reported and left as it is. */
    #[Test]
    public function a_missing_signed_copy_is_reported_and_its_record_left_alone(): void
    {
        Storage::disk('public')->delete(self::CERT);

        $this->artisan('audit:move-signed-copies')->assertSuccessful();

        $this->assertSame(self::CERT, $this->signature()['certificate_path']);
        $this->assertStringStartsWith('audit/files/', $this->signature()['signed_application_path']);
    }

    /**
     * A signed copy no record points to any more (its application was edited
     * after signing) is still the evidence of that signing: it moves to the
     * audit folder by its hash too.
     */
    #[Test]
    public function a_signed_copy_no_record_points_to_still_moves_to_the_audit_folder(): void
    {
        Storage::disk('public')->put('funding-applications/9/signed/v1-x/application.pdf', '%PDF signed then edited');

        $this->artisan('audit:move-signed-copies')->assertSuccessful();

        $this->assertSame('%PDF signed then edited', Storage::disk('uploads')->get('audit/files/' . hash('sha256', '%PDF signed then edited') . '.pdf'));
        $this->assertFalse(Storage::disk('public')->exists('funding-applications'));
    }

    #[Test]
    public function a_dry_run_lists_only_what_would_really_stay(): void
    {
        Storage::disk('public')->put('funding-applications/1/stray.pdf', '%PDF stray');

        $this->artisan('audit:move-signed-copies', ['--dry-run' => true])
            ->expectsOutputToContain('Kept (not a signed copy): funding-applications/1/stray.pdf')
            ->doesntExpectOutputToContain('Kept (not a signed copy): ' . self::APP)
            ->assertSuccessful();
    }

    /** A file under funding-applications/ that is not a signed copy is listed, never deleted. */
    #[Test]
    public function a_file_no_record_points_to_is_kept_and_listed(): void
    {
        Storage::disk('public')->put('funding-applications/1/stray.pdf', '%PDF stray');

        $this->artisan('audit:move-signed-copies')
            ->expectsOutputToContain('funding-applications/1/stray.pdf')
            ->assertSuccessful();

        Storage::disk('public')->assertExists('funding-applications/1/stray.pdf');
    }
}
