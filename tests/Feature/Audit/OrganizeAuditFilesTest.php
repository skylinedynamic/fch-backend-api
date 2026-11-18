<?php

namespace Tests\Feature\Audit;

use App\Models\Audit\FundingApplicationAuditEvent;
use App\Models\Borrower\FundingApplication;
use App\Services\Audit\FundingApplicationAuditTrail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/**
 * audit:organize-files - the copies kept flat as audit/files/{sha256}.pdf
 * move under their application and the version signed,
 * audit/applications/{id}/v{n}/{application|certificate}-{sha256}.pdf, and
 * the signature records follow.
 *
 * The audit events are never touched (their hashes cover file_path), so the
 * chain stays intact; the trail finds a moved copy by its hash. A flat copy
 * goes only once every copy made of it matches its hash.
 */
class OrganizeAuditFilesTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithPortalUsers;

    private const APP_PDF = '%PDF application as signed';
    private const CERT_PDF = '%PDF certificate';
    private const OLD_PDF = '%PDF first signing';

    private FundingApplicationAuditTrail $trail;
    private int $applicationId;
    private int $partnerId;
    private string $appHash;
    private string $certHash;
    private string $oldHash;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        $this->trail = app(FundingApplicationAuditTrail::class);
        $partner = $this->makeWhiteLabelPartner();
        $this->partnerId = $partner->id;
        $this->applicationId = $this->application($partner->id, 'FUND-0000303');

        $this->appHash = $this->keepFlat(self::APP_PDF);
        $this->certHash = $this->keepFlat(self::CERT_PDF);
        $this->oldHash = $this->keepFlat(self::OLD_PDF);

        $this->signedCopyKept($this->applicationId, '1', FundingApplicationAuditEvent::FILE_APPLICATION_PDF, $this->oldHash);
        $this->signedCopyKept($this->applicationId, '2', FundingApplicationAuditEvent::FILE_APPLICATION_PDF, $this->appHash);
        $this->signedCopyKept($this->applicationId, '2', FundingApplicationAuditEvent::FILE_SIGNATURE_CERTIFICATE, $this->certHash);

        DB::table('funding_applications')->where('id', $this->applicationId)->update([
            'signature_data' => json_encode([
                'application_version' => 2,
                'sha256' => $this->appHash,
                'signed_application_path' => $this->flat($this->appHash),
                'certificate_path' => $this->flat($this->certHash),
            ]),
            'previous_signature_data' => json_encode([
                'application_version' => 1,
                'signed_application_path' => $this->flat($this->oldHash),
            ]),
        ]);
    }

    private function application(int $partnerId, string $number): int
    {
        return DB::table('funding_applications')->insertGetId([
            'application_number' => $number,
            'borrower_id' => $this->makeBorrower($this->makeWhiteLabelPartner())->id,
            'partner_id' => $partnerId,
            'status' => FundingApplication::SUBMITTED,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function keepFlat(string $contents): string
    {
        return $this->trail->keepContents($contents, 'pdf')['hash'];
    }

    private function flat(string $hash): string
    {
        return "audit/files/{$hash}.pdf";
    }

    private function organized(int $applicationId, string $version, string $name, string $hash): string
    {
        return "audit/applications/{$applicationId}/v{$version}/{$name}-{$hash}.pdf";
    }

    private function signedCopyKept(int $applicationId, string $version, string $fileType, string $hash): FundingApplicationAuditEvent
    {
        return $this->trail->append([
            'funding_application_id' => $applicationId,
            'partner_id' => $this->partnerId,
            'event' => FundingApplicationAuditEvent::EVENT_SIGNED_COPY_KEPT,
            'context' => ['file_type' => $fileType, 'application_version' => $version],
            'file_hash' => $hash,
            'file_path' => $this->flat($hash),
        ]);
    }

    private function record(string $column = 'signature_data'): array
    {
        return json_decode(DB::table('funding_applications')->where('id', $this->applicationId)->value($column), true);
    }

    private function eventRows(): array
    {
        return DB::table('funding_application_audit_events')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
    }

    #[Test]
    public function a_dry_run_changes_nothing(): void
    {
        $events = $this->eventRows();

        $this->artisan('audit:organize-files', ['--dry-run' => true])->assertSuccessful();

        $this->assertSame($this->flat($this->appHash), $this->record()['signed_application_path']);
        Storage::disk('uploads')->assertExists($this->flat($this->appHash));
        $this->assertSame([], Storage::disk('uploads')->allFiles('audit/applications'));
        $this->assertSame($events, $this->eventRows());
    }

    #[Test]
    public function each_copy_moves_under_its_application_and_version_and_the_records_follow(): void
    {
        $this->artisan('audit:organize-files')->assertSuccessful();

        $uploads = Storage::disk('uploads');
        $app = $this->organized($this->applicationId, '2', 'application', $this->appHash);
        $cert = $this->organized($this->applicationId, '2', 'certificate', $this->certHash);
        $old = $this->organized($this->applicationId, '1', 'application', $this->oldHash);

        $this->assertSame(self::APP_PDF, $uploads->get($app));
        $this->assertSame(self::CERT_PDF, $uploads->get($cert));
        $this->assertSame(self::OLD_PDF, $uploads->get($old));
        $this->assertSame($app, $this->record()['signed_application_path']);
        $this->assertSame($cert, $this->record()['certificate_path']);
        $this->assertSame($old, $this->record('previous_signature_data')['signed_application_path']);
        // The rest of the record is left as it was.
        $this->assertSame(2, $this->record()['application_version']);
        $this->assertSame($this->appHash, $this->record()['sha256']);

        $this->assertSame([], $uploads->files('audit/files'));
    }

    #[Test]
    public function the_events_are_untouched_and_every_chain_still_verifies(): void
    {
        $events = $this->eventRows();

        $this->artisan('audit:organize-files')->assertSuccessful();

        $this->assertSame($events, $this->eventRows());
        $this->assertTrue($this->trail->verify($this->applicationId)['valid']);
        $this->artisan('audit:verify')->assertSuccessful();
    }

    #[Test]
    public function the_trail_still_hands_out_each_moved_copy(): void
    {
        $this->artisan('audit:organize-files')->assertSuccessful();

        // A fresh trail: the one above remembered the application's files before they moved.
        $trail = new FundingApplicationAuditTrail();
        $kept = FundingApplicationAuditEvent::where('funding_application_id', $this->applicationId)->orderBy('id')->get();
        $this->assertSame(
            [self::OLD_PDF, self::APP_PDF, self::CERT_PDF],
            $kept->map(fn ($event) => Storage::disk('uploads')->get($trail->storedFile($event)))->all()
        );
    }

    #[Test]
    public function running_it_again_changes_nothing(): void
    {
        $this->artisan('audit:organize-files')->assertSuccessful();
        $files = Storage::disk('uploads')->allFiles('audit');
        $record = $this->record();

        $this->artisan('audit:organize-files')->assertSuccessful();

        $this->assertSame($files, Storage::disk('uploads')->allFiles('audit'));
        $this->assertSame($record, $this->record());
    }

    #[Test]
    public function a_copy_that_no_longer_matches_its_hash_stays_where_it_is(): void
    {
        Storage::disk('uploads')->put($this->flat($this->certHash), '%PDF altered');

        $this->artisan('audit:organize-files')->assertSuccessful();

        Storage::disk('uploads')->assertExists($this->flat($this->certHash));
        $this->assertSame($this->flat($this->certHash), $this->record()['certificate_path']);
        Storage::disk('uploads')->assertMissing($this->organized($this->applicationId, '2', 'certificate', $this->certHash));
        // The others still move.
        $this->assertSame($this->organized($this->applicationId, '2', 'application', $this->appHash), $this->record()['signed_application_path']);
    }

    #[Test]
    public function a_flat_file_nothing_points_to_is_reported_and_kept(): void
    {
        $orphan = $this->keepFlat('%PDF nobody names');

        $this->artisan('audit:organize-files')->assertSuccessful();

        Storage::disk('uploads')->assertExists($this->flat($orphan));
    }

    #[Test]
    public function a_copy_two_applications_share_goes_under_each_of_them(): void
    {
        $otherId = $this->application($this->partnerId, 'FUND-0000304');
        $this->signedCopyKept($otherId, '1', FundingApplicationAuditEvent::FILE_SIGNATURE_CERTIFICATE, $this->certHash);

        $this->artisan('audit:organize-files')->assertSuccessful();

        $uploads = Storage::disk('uploads');
        $this->assertSame(self::CERT_PDF, $uploads->get($this->organized($this->applicationId, '2', 'certificate', $this->certHash)));
        $this->assertSame(self::CERT_PDF, $uploads->get($this->organized($otherId, '1', 'certificate', $this->certHash)));
        $uploads->assertMissing($this->flat($this->certHash));
    }

    /** A copy the trail names without a version is still filed under its application. */
    #[Test]
    public function a_copy_recorded_without_a_version_goes_under_unversioned(): void
    {
        $hash = $this->keepFlat('%PDF version unknown');
        $this->trail->append([
            'funding_application_id' => $this->applicationId,
            'partner_id' => $this->partnerId,
            'event' => FundingApplicationAuditEvent::EVENT_SIGNED_COPY_KEPT,
            'context' => ['file_type' => FundingApplicationAuditEvent::FILE_APPLICATION_PDF],
            'file_hash' => $hash,
            'file_path' => $this->flat($hash),
        ]);

        $this->artisan('audit:organize-files')->assertSuccessful();

        Storage::disk('uploads')->assertExists("audit/applications/{$this->applicationId}/unversioned/application-{$hash}.pdf");
        Storage::disk('uploads')->assertMissing($this->flat($hash));
    }

    /**
     * An imported event (the old download history) names its copy by hash
     * only, with no path and a version of its own ("1.0"). Its flat copy goes
     * under its application too - unless a record already put that copy there.
     */
    #[Test]
    public function an_imported_events_flat_copy_goes_under_its_application_once(): void
    {
        $imported = fn (string $hash) => $this->trail->append([
            'funding_application_id' => $this->applicationId,
            'partner_id' => $this->partnerId,
            'event' => FundingApplicationAuditEvent::EVENT_SIGNED_COPY_KEPT,
            'context' => ['file_type' => FundingApplicationAuditEvent::FILE_APPLICATION_PDF, 'application_version' => '1.0'],
            'file_hash' => $hash,
            'is_legacy' => true,
        ]);
        $onlyImported = $this->keepFlat('%PDF only the history names');
        $imported($onlyImported);
        $imported($this->oldHash);

        $this->artisan('audit:organize-files')->assertSuccessful();

        $uploads = Storage::disk('uploads');
        $uploads->assertExists("audit/applications/{$this->applicationId}/v1.0/application-{$onlyImported}.pdf");
        $uploads->assertMissing($this->flat($onlyImported));
        // Already under v1 from the record: no second copy under v1.0.
        $uploads->assertMissing("audit/applications/{$this->applicationId}/v1.0/application-{$this->oldHash}.pdf");
        $this->assertSame([], $uploads->files('audit/files'));
    }

    /** The seals are left where they are. */
    #[Test]
    public function the_seals_are_not_touched(): void
    {
        Storage::disk('uploads')->put('audit/seals/2026-10-31.json', '{"day":"31"}');

        $this->artisan('audit:organize-files')->assertSuccessful();

        $this->assertSame(['audit/seals/2026-10-31.json'], Storage::disk('uploads')->allFiles('audit/seals'));
    }
}
