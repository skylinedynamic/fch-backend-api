<?php

namespace Tests\Feature\Application;

use App\Models\Audit\FundingApplicationAuditEvent;
use App\Models\Borrower\FundingApplication;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/**
 * Defect: BUG-094. The download password went in the URL
 * (download-zip?password=...), so it landed in server and proxy logs, and it
 * was also saved in plain text in the download history. A signed application
 * was re-rendered on every download instead of handing out the copy that was
 * signed.
 */
class ApplicationDownloadPasswordTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithPortalUsers;

    private int $applicationId;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Storage::fake('public');

        $host = $this->makeHostPartner();
        $borrower = $this->makeBorrower($host);
        $this->applicationId = DB::table('funding_applications')->insertGetId([
            'application_number' => 'FUND-0000086',
            'borrower_id' => $borrower->id,
            'partner_id' => $host->id,
            'status' => FundingApplication::SUBMITTED,
            'business_address' => '400 QA Biz Ave',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAsUser($this->makePartnerAdmin($host));
    }

    private function url(string $what): string
    {
        return "/api/v1/common/request-application/funding-application/{$this->applicationId}/{$what}";
    }

    #[Test]
    public function the_password_is_taken_from_the_body_and_never_stored(): void
    {
        $this->post($this->url('download-application'), ['password' => 'Secret#2026'])->assertOk();

        $event = FundingApplicationAuditEvent::where('funding_application_id', $this->applicationId)
            ->where('event', FundingApplicationAuditEvent::EVENT_DOWNLOADED)->latest('id')->firstOrFail();
        $this->assertTrue($event->context['password_protected']);
        $this->assertArrayNotHasKey('password', (array) $event->context);
        $this->assertStringNotContainsString('Secret#2026', json_encode($event->getAttributes()));
    }

    #[Test]
    public function a_password_in_the_url_is_refused(): void
    {
        foreach (['download-application', 'download-signature', 'download-zip'] as $what) {
            $this->getJson($this->url($what) . '?password=Secret%232026')->assertStatus(422);
        }

        $this->assertSame(0, FundingApplicationAuditEvent::where('funding_application_id', $this->applicationId)->where('event', FundingApplicationAuditEvent::EVENT_DOWNLOADED)->count());
    }

    #[Test]
    public function a_signed_application_downloads_the_copy_that_was_signed(): void
    {
        $path = "funding-applications/{$this->applicationId}/signed/v1-cert/application.pdf";
        Storage::disk('public')->put($path, '%PDF-1.7 the signed copy');
        FundingApplication::whereKey($this->applicationId)->update([
            'signature_data' => json_encode(['signed_at' => '2026-10-19T08:30:00+00:00', 'signed_application_path' => $path]),
        ]);

        $response = $this->post($this->url('download-application'))->assertOk();

        $this->assertSame('%PDF-1.7 the signed copy', $response->getContent());
    }
}
