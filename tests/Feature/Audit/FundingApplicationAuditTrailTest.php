<?php

namespace Tests\Feature\Audit;

use App\Models\Audit\FundingApplicationAuditEvent;
use App\Http\Requests\Api\V1\FCH\Common\Audit\FundingApplicationAuditTrailRequest;
use App\Models\Borrower\Borrower;
use App\Models\Borrower\FundingApplication;
use App\Models\Borrower\FundingApplicationCoBorrower;
use App\Models\Borrower\FundingApplicationDocument;
use App\Models\Commission\Commission;
use App\Models\Partner\Partner;
use App\Models\User\User;
use App\Services\Audit\FundingApplicationAuditTrail;
use App\Services\Audit\FundingApplicationAuditTrailPdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;
use Illuminate\Support\Collection;
use LogicException;

/**
 * Funding application audit trail: every change to an application's data is
 * an append-only, hash-chained event - who, what (each field, before and
 * after), when (UTC), from where - and the files it concerns are kept in the
 * audit folder by their hash.
 *
 * Replaces the download-only "Audit History" (download_histories), which
 * recorded nothing but downloads, could be rewritten, and served a download
 * copy that every later download overwrote.
 */
class FundingApplicationAuditTrailTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithPortalUsers;

    private Partner $host;
    private Partner $partner;
    private Partner $otherPartner;
    private User $hostAdmin;
    private User $partnerAdmin;
    private User $otherPartnerAdmin;
    private Borrower $borrower;
    private FundingApplication $application;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Storage::fake('public');

        $this->host = $this->makeHostPartner();
        $this->partner = $this->makeWhiteLabelPartner();
        $this->otherPartner = $this->makeWhiteLabelPartner();
        $this->hostAdmin = $this->makePartnerAdmin($this->host, [], ['name' => 'Hana Host']);
        $this->partnerAdmin = $this->makePartnerAdmin($this->partner, [], ['name' => 'Pat Partner']);
        $this->otherPartnerAdmin = $this->makePartnerAdmin($this->otherPartner);
        $this->borrower = $this->makeBorrower($this->partner);

        $id = DB::table('funding_applications')->insertGetId([
            'application_number' => 'FUND-0000101',
            'borrower_id' => $this->borrower->id,
            'partner_id' => $this->partner->id,
            'status' => FundingApplication::SUBMITTED,
            'business_name' => 'Acme LLC',
            'requested_amount' => 50000,
            'ssn' => '123-45-6789',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->application = FundingApplication::findOrFail($id);
    }

    private function trailUrl(?int $applicationId = null, string $suffix = ''): string
    {
        return '/api/v1/common/request-application/funding-application/'
            . ($applicationId ?? $this->application->id) . '/audit-trail' . $suffix;
    }

    /** A signed copy kept in the audit folder, as signing records it. */
    private function keptSignedCopy(string $contents): FundingApplicationAuditEvent
    {
        $kept = app(FundingApplicationAuditTrail::class)->keepContents($contents, 'pdf');

        return app(FundingApplicationAuditTrail::class)->append([
            'funding_application_id' => $this->application->id,
            'partner_id' => $this->partner->id,
            'event' => FundingApplicationAuditEvent::EVENT_SIGNED_COPY_KEPT,
            'context' => ['file_type' => FundingApplicationAuditEvent::FILE_APPLICATION_PDF, 'name' => 'Signed application.pdf'],
            'file_hash' => $kept['hash'],
            'file_path' => $kept['path'],
        ]);
    }

    private function events(?string $event = null): Collection
    {
        return FundingApplicationAuditEvent::where('funding_application_id', $this->application->id)
            ->when($event, fn ($q) => $q->where('event', $event))
            ->orderBy('id')
            ->get();
    }

    // ── What is recorded ────────────────────────────────────────────────

    #[Test]
    public function a_change_records_each_changed_field_before_and_after_with_who_and_when(): void
    {
        $this->actingAsUser($this->partnerAdmin);

        $this->application->update(['business_name' => 'Acme Holdings LLC', 'requested_amount' => 75000]);

        $event = $this->events(FundingApplicationAuditEvent::EVENT_UPDATED)->sole();
        $changes = collect($event->changes)->keyBy('field');

        $this->assertSame(['business_name', 'requested_amount'], $changes->keys()->sort()->values()->all());
        $this->assertSame('Acme LLC', $changes['business_name']['old']);
        $this->assertSame('Acme Holdings LLC', $changes['business_name']['new']);
        $this->assertSame('50000.00', $changes['requested_amount']['old']);
        $this->assertSame('75000.00', $changes['requested_amount']['new']);
        $this->assertSame($this->partnerAdmin->id, $event->actor_user_id);
        $this->assertSame('Pat Partner', $event->actor_name);
        $this->assertNotNull($event->occurred_at);
    }

    #[Test]
    public function a_save_that_changes_nothing_records_nothing(): void
    {
        $this->actingAsUser($this->partnerAdmin);

        $this->application->update(['business_name' => 'Acme LLC']);
        $this->application->touch();

        $this->assertCount(0, $this->events(FundingApplicationAuditEvent::EVENT_UPDATED));
    }

    #[Test]
    public function an_ssn_change_is_recorded_masked(): void
    {
        $this->actingAsUser($this->partnerAdmin);

        $this->application->update(['ssn' => '987-65-4321']);

        $change = collect($this->events(FundingApplicationAuditEvent::EVENT_UPDATED)->sole()->changes)->firstWhere('field', 'ssn');
        $this->assertSame('***-**-6789', $change['old']);
        $this->assertSame('***-**-4321', $change['new']);
        $this->assertStringNotContainsString('987-65', json_encode(FundingApplicationAuditEvent::all()->toArray()));
    }

    #[Test]
    public function a_status_change_through_the_api_is_its_own_event_with_the_note(): void
    {
        $this->actingAsUser($this->hostAdmin);

        $this->postJson('/api/v1/common/request-application/funding-application/status-update', [
            'funding_application_id' => $this->application->id,
            'status' => FundingApplication::UNDERWRITING_REVIEW,
            'status_note' => 'Docs complete',
        ])->assertOk();

        $event = $this->events(FundingApplicationAuditEvent::EVENT_STATUS_CHANGED)->sole();
        $status = collect($event->changes)->firstWhere('field', 'status');
        $this->assertSame(FundingApplication::SUBMITTED, $status['old']);
        $this->assertSame(FundingApplication::UNDERWRITING_REVIEW, $status['new']);
        $this->assertSame('Docs complete', collect($event->changes)->firstWhere('field', 'status_note')['new']);
        $this->assertSame('Hana Host', $event->actor_name);
    }

    #[Test]
    public function signing_and_a_signature_cleared_by_an_edit_are_events(): void
    {
        $this->actingAsUser($this->partnerAdmin);

        $this->application->update(['signature_data' => ['signed_at' => '2026-11-03T10:00:00+00:00', 'certificate_id' => 'CERT-1']]);
        $this->application->update(['signature_data' => null, 'requires_resign' => true]);

        $this->assertCount(1, $this->events(FundingApplicationAuditEvent::EVENT_SIGNED));
        $this->assertCount(1, $this->events(FundingApplicationAuditEvent::EVENT_SIGNATURE_CLEARED));
        // The signature blob itself is never copied into the trail.
        $this->assertStringNotContainsString('CERT-1', json_encode($this->events(FundingApplicationAuditEvent::EVENT_UPDATED)->toArray()));
    }

    #[Test]
    public function a_document_is_recorded_by_its_hash_and_no_copy_is_kept(): void
    {
        $this->actingAsUser($this->partnerAdmin);
        Storage::disk('public')->put('funding-application/1/FA-1/bank.pdf', '%PDF bank statement');

        $document = FundingApplicationDocument::create([
            'funding_application_id' => $this->application->id,
            'borrower_id' => $this->borrower->id,
            'name' => 'Bank statement.pdf',
            'original_name' => 'Bank statement.pdf',
            'path' => 'funding-application/1/FA-1/bank.pdf',
            'disk' => 'public',
        ]);

        $added = $this->events(FundingApplicationAuditEvent::EVENT_DOCUMENT_ADDED)->sole();
        $hash = hash('sha256', '%PDF bank statement');
        $this->assertSame($hash, $added->file_hash);
        $this->assertNull($added->file_path);
        $this->assertSame([], Storage::disk('uploads')->allFiles('audit'));

        // Removed the way the services do it: the file first, then the record.
        Storage::disk('public')->delete('funding-application/1/FA-1/bank.pdf');
        $document->forceDelete();

        $removed = $this->events(FundingApplicationAuditEvent::EVENT_DOCUMENT_REMOVED)->sole();
        $this->assertSame($hash, $removed->file_hash);
        $this->assertNull($removed->file_path);
        $this->assertSame([], Storage::disk('uploads')->allFiles('audit'));
    }

    #[Test]
    public function the_same_file_added_twice_has_the_same_hash(): void
    {
        $this->actingAsUser($this->partnerAdmin);
        Storage::disk('public')->put('a/one.pdf', '%PDF same');
        Storage::disk('public')->put('a/two.pdf', '%PDF same');

        foreach (['a/one.pdf', 'a/two.pdf'] as $path) {
            FundingApplicationDocument::create([
                'funding_application_id' => $this->application->id,
                'borrower_id' => $this->borrower->id,
                'name' => basename($path),
                'path' => $path,
                'disk' => 'public',
            ]);
        }

        $added = $this->events(FundingApplicationAuditEvent::EVENT_DOCUMENT_ADDED);
        $this->assertCount(2, $added);
        $this->assertSame([hash('sha256', '%PDF same')], $added->pluck('file_hash')->unique()->values()->all());
    }

    #[Test]
    public function a_document_whose_file_is_missing_is_still_recorded(): void
    {
        $this->actingAsUser($this->partnerAdmin);

        FundingApplicationDocument::create([
            'funding_application_id' => $this->application->id,
            'borrower_id' => $this->borrower->id,
            'name' => 'gone.pdf',
            'path' => 'nowhere/gone.pdf',
            'disk' => 'public',
        ]);

        $event = $this->events(FundingApplicationAuditEvent::EVENT_DOCUMENT_ADDED)->sole();
        $this->assertNull($event->file_hash);
        $this->assertSame('gone.pdf', $event->context['name']);
    }

    #[Test]
    public function co_borrower_changes_are_recorded_with_the_ssn_masked(): void
    {
        $this->actingAsUser($this->partnerAdmin);

        $coBorrower = FundingApplicationCoBorrower::create([
            'funding_application_id' => $this->application->id,
            'first_name' => 'Cora',
            'last_name' => 'Owner',
            'ownership_percentage' => 25,
            'ssn' => '111-22-3333',
        ]);
        $coBorrower->update(['ownership_percentage' => 30]);
        $coBorrower->forceDelete();

        $this->assertCount(1, $this->events(FundingApplicationAuditEvent::EVENT_CO_BORROWER_ADDED));
        $updated = $this->events(FundingApplicationAuditEvent::EVENT_CO_BORROWER_UPDATED)->sole();
        $this->assertSame('25.00', collect($updated->changes)->firstWhere('field', 'ownership_percentage')['old']);
        $this->assertCount(1, $this->events(FundingApplicationAuditEvent::EVENT_CO_BORROWER_REMOVED));
        $this->assertStringNotContainsString('111-22', json_encode(FundingApplicationAuditEvent::all()->toArray()));
    }

    #[Test]
    public function commission_changes_are_not_recorded(): void
    {
        $this->actingAsUser($this->hostAdmin);

        $commission = Commission::create([
            'funding_application_id' => $this->application->id,
            'borrower_id' => $this->borrower->id,
            'partner_id' => $this->partner->id,
            'admin_commission' => 2,
        ]);
        $commission->update(['admin_commission' => 3]);

        $commission->delete();

        // Commission is not part of the application's audit trail.
        $this->assertCount(0, $this->events());
        $this->assertNotContains('commission_changed', FundingApplicationAuditEvent::EVENTS);
    }

    #[Test]
    public function removing_and_restoring_the_application_are_recorded(): void
    {
        $this->actingAsUser($this->hostAdmin);

        $this->application->delete();
        $this->application->restore();

        $this->assertCount(1, $this->events(FundingApplicationAuditEvent::EVENT_DELETED));
        $this->assertCount(1, $this->events(FundingApplicationAuditEvent::EVENT_RESTORED));
    }

    #[Test]
    public function a_download_is_recorded_with_its_hash_and_no_copy_is_saved(): void
    {
        $this->actingAsUser($this->partnerAdmin);
        $path = "funding-applications/{$this->application->id}/signed/v1-cert/application.pdf";
        Storage::disk('public')->put($path, '%PDF-1.7 the signed copy');
        $this->application->updateQuietly(['signature_data' => ['signed_at' => '2026-10-19T08:30:00+00:00', 'signed_application_path' => $path]]);

        $this->post("/api/v1/common/request-application/funding-application/{$this->application->id}/download-application")->assertOk();

        $event = $this->events(FundingApplicationAuditEvent::EVENT_DOWNLOADED)->sole();
        $this->assertSame(hash('sha256', '%PDF-1.7 the signed copy'), $event->file_hash);
        $this->assertSame('application_pdf', $event->context['file_type']);
        $this->assertFalse($event->context['password_protected']);
        $this->assertSame([], Storage::disk('public')->allFiles("funding-applications/{$this->application->id}/downloads"));
        $this->assertSame([], Storage::disk('public')->allFiles('storage'));
    }

    #[Test]
    public function a_password_protected_download_records_that_a_password_was_used_never_the_password(): void
    {
        $this->actingAsUser($this->partnerAdmin);
        // A ZIP is of a signed application.
        $this->application->updateQuietly(['signature_data' => ['signed_at' => '2026-10-19T08:30:00+00:00']]);

        $this->post("/api/v1/common/request-application/funding-application/{$this->application->id}/download-zip", ['password' => 'Secret#2026'])->assertOk();

        $event = $this->events(FundingApplicationAuditEvent::EVENT_DOWNLOADED)->sole();
        $this->assertTrue($event->context['password_protected']);
        $this->assertSame('application_zip', $event->context['file_type']);
        $this->assertStringNotContainsString('Secret#2026', json_encode($event->getAttributes()));
    }

    // ── Integrity ───────────────────────────────────────────────────────

    #[Test]
    public function each_event_is_chained_to_the_one_before_it(): void
    {
        $this->actingAsUser($this->partnerAdmin);

        $this->application->update(['business_name' => 'One']);
        $this->application->update(['business_name' => 'Two']);

        [$first, $second] = $this->events()->all();
        $this->assertNull($first->prev_hash);
        $this->assertSame($first->hash, $second->prev_hash);
        $this->assertTrue(app(FundingApplicationAuditTrail::class)->verify($this->application->id)['valid']);
    }

    #[Test]
    public function a_row_changed_behind_the_trails_back_is_detected(): void
    {
        $this->actingAsUser($this->partnerAdmin);
        $this->application->update(['business_name' => 'One']);
        $this->application->update(['business_name' => 'Two']);
        $first = $this->events()->first();

        DB::table('funding_application_audit_events')->where('id', $first->id)->update(['actor_name' => 'Someone Else']);

        $result = app(FundingApplicationAuditTrail::class)->verify($this->application->id);
        $this->assertFalse($result['valid']);
        $this->assertSame($first->id, $result['broken_at']);
    }

    #[Test]
    public function an_event_cannot_be_changed_or_deleted_through_the_model(): void
    {
        $this->actingAsUser($this->partnerAdmin);
        $this->application->update(['business_name' => 'One']);
        $event = $this->events()->first();

        try {
            $event->update(['actor_name' => 'X']);
            $this->fail('An audit event was updated.');
        } catch (LogicException) {
        }

        try {
            $event->delete();
            $this->fail('An audit event was deleted.');
        } catch (LogicException) {
        }

        $this->assertSame('Pat Partner', $event->fresh()->actor_name);
    }

    #[Test]
    public function the_trail_outlives_the_applications_row(): void
    {
        $this->actingAsUser($this->hostAdmin);
        $this->application->update(['business_name' => 'One']);

        // A hard delete must not take the record of the application with it.
        DB::table('funding_applications')->where('id', $this->application->id)->delete();

        $this->assertSame(1, FundingApplicationAuditEvent::where('funding_application_id', $this->application->id)->count());
    }

    // ── Reading the trail ───────────────────────────────────────────────

    #[Test]
    public function the_host_and_the_applications_own_partner_read_the_trail_newest_first(): void
    {
        $this->actingAsUser($this->partnerAdmin);
        $this->application->update(['business_name' => 'One']);
        $this->application->update(['business_name' => 'Two']);

        foreach ([$this->hostAdmin, $this->partnerAdmin] as $user) {
            $this->actingAsUser($user);
            $rows = $this->getJson($this->trailUrl())->assertOk()->json('data');

            $this->assertCount(2, $rows);
            $this->assertSame('Two', collect($rows[0]['changes'])->firstWhere('field', 'business_name')['new']);
            $this->assertSame('Business Name', collect($rows[0]['changes'])->firstWhere('field', 'business_name')['label']);
            $this->assertSame('Pat Partner', $rows[0]['actor']['name']);
        }
    }

    #[Test]
    public function another_partner_cannot_read_the_trail_or_its_files(): void
    {
        $event = $this->keptSignedCopy('%PDF secret');

        $this->actingAsUser($this->otherPartnerAdmin);

        $this->getJson($this->trailUrl())->assertNotFound();
        $this->getJson($this->trailUrl(null, '/export'))->assertNotFound();
        $response = $this->getJson($this->trailUrl(null, "/{$event->id}/file"));
        $response->assertNotFound();
        $this->assertStringNotContainsString('%PDF secret', (string) $response->getContent());
    }

    #[Test]
    public function the_trail_filters_by_event_and_caps_the_page_size(): void
    {
        $this->actingAsUser($this->hostAdmin);
        $this->application->update(['business_name' => 'One']);
        $this->postJson('/api/v1/common/request-application/funding-application/status-update', [
            'funding_application_id' => $this->application->id,
            'status' => FundingApplication::UNDERWRITING_REVIEW,
        ])->assertOk();

        $rows = $this->getJson($this->trailUrl() . '?event=' . FundingApplicationAuditEvent::EVENT_STATUS_CHANGED)->assertOk()->json('data');
        $this->assertSame([FundingApplicationAuditEvent::EVENT_STATUS_CHANGED], array_unique(array_column($rows, 'event')));

        $this->getJson($this->trailUrl() . '?per_page=100000')->assertOk()
            ->assertJsonPath('pagination.per_page', FundingApplicationAuditTrail::MAX_PER_PAGE);
        $this->getJson($this->trailUrl() . '?event=not_an_event')->assertStatus(422);
        $this->getJson($this->trailUrl() . '?sort_dir=sideways')->assertStatus(422);
    }

    #[Test]
    public function the_trail_says_whether_its_chain_is_intact(): void
    {
        $this->actingAsUser($this->hostAdmin);
        $this->application->update(['business_name' => 'One']);

        $this->getJson($this->trailUrl())->assertOk()->assertJsonPath('integrity.valid', true);

        DB::table('funding_application_audit_events')->update(['actor_name' => 'Tampered']);

        $this->getJson($this->trailUrl())->assertOk()->assertJsonPath('integrity.valid', false);
    }

    #[Test]
    public function the_trail_exports_as_csv(): void
    {
        $this->actingAsUser($this->hostAdmin);
        $this->application->update(['business_name' => 'One']);

        $response = $this->get($this->trailUrl(null, '/export'))->assertOk();

        $this->assertStringContainsString('text/csv', $response->headers->get('Content-Type'));
        $csv = $response->streamedContent();
        $this->assertStringContainsString('Business Name', $csv);
        $this->assertStringContainsString('Hana Host', $csv);
    }

    #[Test]
    public function the_trail_exports_as_pdf(): void
    {
        $this->actingAsUser($this->hostAdmin);
        $this->application->update(['business_name' => 'One']);

        $response = $this->get($this->trailUrl(null, '/export?format=' . FundingApplicationAuditTrailRequest::EXPORT_PDF))->assertOk();

        $this->assertStringContainsString('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringContainsString("audit-trail-application-{$this->application->id}.pdf", $response->headers->get('Content-Disposition'));
        $this->assertStringStartsWith('%PDF', $response->streamedContent());
    }

    #[Test]
    public function the_pdf_export_lists_each_event_with_its_changes(): void
    {
        $this->actingAsUser($this->hostAdmin);
        $this->application->update(['business_name' => 'Renamed Co']);

        $html = view(FundingApplicationAuditTrailPdf::VIEW, app(FundingApplicationAuditTrailPdf::class)
            ->data($this->application->id, $this->events()))->render();

        $this->assertStringContainsString('Audit Trail', $html);
        $this->assertStringContainsString('Application updated', $html);
        $this->assertStringContainsString('Business Name', $html);
        $this->assertStringContainsString('Renamed Co', $html);
        $this->assertStringContainsString('Hana Host', $html);
        $this->assertStringContainsString('Integrity verified', $html);
    }

    #[Test]
    public function an_unknown_export_format_is_refused(): void
    {
        $this->actingAsUser($this->hostAdmin);

        $this->getJson($this->trailUrl(null, '/export?format=xls'))->assertStatus(422);
    }

    #[Test]
    public function another_partner_cannot_export_the_trail_as_pdf(): void
    {
        $this->actingAsUser($this->otherPartnerAdmin);

        $this->getJson($this->trailUrl(null, '/export?format=' . FundingApplicationAuditTrailRequest::EXPORT_PDF))->assertNotFound();
    }

    #[Test]
    public function a_kept_signed_copy_downloads_for_the_owner(): void
    {
        $event = $this->keptSignedCopy('%PDF kept');
        $this->actingAsUser($this->partnerAdmin);

        $response = $this->get($this->trailUrl(null, "/{$event->id}/file"))->assertOk();

        $this->assertSame('%PDF kept', $response->streamedContent());
        $this->assertStringContainsString('Signed application.pdf', $response->headers->get('Content-Disposition'));
    }

    #[Test]
    public function a_documents_event_has_no_file_to_download(): void
    {
        $this->actingAsUser($this->partnerAdmin);
        Storage::disk('public')->put('x/doc.pdf', '%PDF tax return');
        FundingApplicationDocument::create([
            'funding_application_id' => $this->application->id, 'borrower_id' => $this->borrower->id,
            'name' => 'doc.pdf', 'original_name' => 'Tax return.pdf', 'path' => 'x/doc.pdf', 'disk' => 'public',
        ]);
        $event = $this->events(FundingApplicationAuditEvent::EVENT_DOCUMENT_ADDED)->sole();
        // Even when a file with that hash sits in the audit folder.
        Storage::disk('uploads')->put("audit/files/{$event->file_hash}.pdf", '%PDF tax return');

        $row = collect($this->getJson($this->trailUrl())->assertOk()->json('data'))->firstWhere('id', $event->id);
        $this->assertSame($event->file_hash, $row['file']['sha256']);
        $this->assertFalse($row['file']['downloadable']);
        $this->getJson($this->trailUrl(null, "/{$event->id}/file"))->assertNotFound();
    }

    /**
     * An imported event names its file by hash only. Once the signed copy is
     * in the audit folder (audit:move-signed-copies), it hands out from there.
     */
    #[Test]
    public function an_imported_events_file_hands_out_from_the_audit_folder_by_its_hash(): void
    {
        $hash = hash('sha256', '%PDF signed long ago');
        Storage::disk('uploads')->put("audit/files/{$hash}.pdf", '%PDF signed long ago');
        $event = app(FundingApplicationAuditTrail::class)->append([
            'funding_application_id' => $this->application->id,
            'partner_id' => $this->partner->id,
            'event' => FundingApplicationAuditEvent::EVENT_SIGNED_COPY_KEPT,
            'context' => ['file_type' => FundingApplicationAuditEvent::FILE_APPLICATION_PDF],
            'file_hash' => $hash,
            'is_legacy' => true,
        ]);
        $this->actingAsUser($this->partnerAdmin);

        $row = collect($this->getJson($this->trailUrl())->assertOk()->json('data'))->firstWhere('id', $event->id);
        $this->assertTrue($row['file']['downloadable']);
        $this->assertSame('%PDF signed long ago', $this->get($this->trailUrl(null, "/{$event->id}/file"))->assertOk()->streamedContent());
    }

    /**
     * A download names what it handed out by hash only. When that is a signed
     * copy kept under its application (applications/{id}/v{n}/...), the
     * download's file is found there by its hash.
     */
    #[Test]
    public function a_downloads_file_hands_out_from_its_applications_folder_by_its_hash(): void
    {
        $kept = app(FundingApplicationAuditTrail::class)->keepSignedCopy('%PDF as signed', $this->application->id, '1', FundingApplicationAuditEvent::FILE_APPLICATION_PDF);
        $event = app(FundingApplicationAuditTrail::class)->append([
            'funding_application_id' => $this->application->id,
            'partner_id' => $this->partner->id,
            'event' => FundingApplicationAuditEvent::EVENT_DOWNLOADED,
            'context' => ['file_type' => FundingApplicationAuditEvent::FILE_APPLICATION_PDF],
            'file_hash' => $kept['hash'],
        ]);

        $this->assertSame($kept['path'], app(FundingApplicationAuditTrail::class)->storedFile($event));
        $this->actingAsUser($this->partnerAdmin);
        $this->assertSame('%PDF as signed', $this->get($this->trailUrl(null, "/{$event->id}/file"))->assertOk()->streamedContent());
    }

    /** The hash is looked for under the event's own application only - never another one's. */
    #[Test]
    public function an_event_never_hands_out_another_applications_copy(): void
    {
        $otherId = DB::table('funding_applications')->insertGetId([
            'application_number' => 'FUND-0000102',
            'borrower_id' => $this->makeBorrower($this->otherPartner)->id,
            'partner_id' => $this->otherPartner->id,
            'status' => FundingApplication::SUBMITTED,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $theirs = app(FundingApplicationAuditTrail::class)->keepSignedCopy('%PDF theirs', $otherId, '1', FundingApplicationAuditEvent::FILE_APPLICATION_PDF);
        $event = app(FundingApplicationAuditTrail::class)->append([
            'funding_application_id' => $this->application->id,
            'partner_id' => $this->partner->id,
            'event' => FundingApplicationAuditEvent::EVENT_DOWNLOADED,
            'context' => ['file_type' => FundingApplicationAuditEvent::FILE_APPLICATION_PDF],
            'file_hash' => $theirs['hash'],
        ]);

        $this->assertNull(app(FundingApplicationAuditTrail::class)->storedFile($event));
    }

    /** A signed copy is kept once: keeping the same bytes again never rewrites it. */
    #[Test]
    public function a_signed_copy_is_never_overwritten(): void
    {
        $trail = app(FundingApplicationAuditTrail::class);
        $first = $trail->keepSignedCopy('%PDF as signed', $this->application->id, '1', FundingApplicationAuditEvent::FILE_SIGNATURE_CERTIFICATE);
        $modified = Storage::disk('uploads')->lastModified($first['path']);

        $again = $trail->keepSignedCopy('%PDF as signed', $this->application->id, '1', FundingApplicationAuditEvent::FILE_SIGNATURE_CERTIFICATE);

        $this->assertSame($first, $again);
        $this->assertSame("audit/applications/{$this->application->id}/v1/certificate-{$first['hash']}.pdf", $first['path']);
        $this->assertSame($modified, Storage::disk('uploads')->lastModified($first['path']));
    }

    #[Test]
    public function reading_or_exporting_the_trail_is_not_itself_an_application_change(): void
    {
        $this->actingAsUser($this->hostAdmin);
        $this->application->update(['business_name' => 'One']);

        $this->getJson($this->trailUrl())->assertOk();
        $this->get($this->trailUrl(null, '/export'))->assertOk();

        $this->assertCount(1, $this->events());
    }
}
