<?php

namespace Tests\Feature\EmbedForm;

use App\Models\ActivityLog\ActivityLog;
use App\Models\Borrower\FundingApplication;
use App\Models\EmbedForm\EmbedFormSubmission;
use App\Models\Partner\Partner;
use App\Services\FCH\Common\Application\FundingApplicationAuditLogger;
use App\Services\FCH\Common\EmbedForm\EmbedFormService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/**
 * Defect: converting an embed form submission (Create Client & Funding
 * Application) created the funding application without a single activity log
 * entry, so its Activity panel and Audit History read "No activity found" and
 * nothing recorded who created it, or from which submission.
 */
#[CoversClass(EmbedFormService::class)]
#[CoversClass(FundingApplicationAuditLogger::class)]
class EmbedFormConversionActivityLogTest extends TestCase
{
    use InteractsWithPortalUsers;
    use RefreshDatabase;

    private Partner $host;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        Mail::fake();
        Notification::fake();

        $this->host = $this->makeHostPartner();
        $this->actingAsUser($this->makePartnerAdmin($this->host, ['view application', 'convert application']));
    }

    #[Test]
    public function converting_a_submission_logs_the_funding_application_it_creates(): void
    {
        $submission = $this->submission();

        $this->postJson("/api/v1/common/embed-forms/submissions/{$submission->id}/convert-to-borrower")->assertOk();

        $application = FundingApplication::findOrFail($submission->fresh()->converted_funding_application_id);
        $logs = ActivityLog::where('subject_type', FundingApplication::class)
            ->where('subject_id', $application->id)
            ->where('event', FundingApplicationAuditLogger::EVENT_CONVERTED_FROM_EMBED_FORM)
            ->get();

        $this->assertCount(1, $logs);
        $log = $logs->first();
        $this->assertSame($application->borrower_id, (int) $log->borrower_id);
        $this->assertSame($submission->id, (int) $log->properties['embed_form_submission_id']);
        $this->assertSame('embed_form', $log->properties['source']);
        $this->assertFalse((bool) $log->properties['is_existing_client']);
        $this->assertStringContainsString($log->properties['actor_name'], $log->description);
        // The person who converted it, by name - not the generic "Partner".
        $this->assertNotSame('Partner', $log->properties['actor_name']);
        $this->assertSame(auth()->id(), (int) $log->causer_id);
        $this->assertStringContainsString($application->application_number, $log->description);
        $this->assertStringContainsString("Embedded form submission #{$submission->id} successfully converted to Funding Application", $log->description);
    }

    #[Test]
    public function converting_for_an_existing_client_logs_it_as_one(): void
    {
        $existing = $this->makeBorrower($this->host, ['email' => 'jimmy@example.test']);
        $submission = $this->submission(['email' => 'jimmy@example.test']);

        $this->postJson("/api/v1/common/embed-forms/submissions/{$submission->id}/convert-to-borrower", [
            'confirm_existing_borrower' => true,
        ])->assertOk();

        $log = ActivityLog::where('subject_type', FundingApplication::class)
            ->where('subject_id', $submission->fresh()->converted_funding_application_id)
            ->where('event', FundingApplicationAuditLogger::EVENT_CONVERTED_FROM_EMBED_FORM)
            ->firstOrFail();

        $this->assertSame($existing->id, (int) $log->borrower_id);
        $this->assertTrue((bool) $log->properties['is_existing_client']);
    }

    private function submission(array $overrides = []): EmbedFormSubmission
    {
        return EmbedFormSubmission::create(array_merge([
            'submission_uid' => (string) Str::uuid(),
            'partner_id' => $this->host->id,
            'first_name' => 'Jimmy',
            'last_name' => 'Thornton',
            'email' => 'craig@example.test',
            'phone' => '+15122345678',
            'business_name' => 'Thornton Holdings LLC',
            'requested_amount' => '25000.00',
        ], $overrides));
    }
}
