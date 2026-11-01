<?php

namespace Tests\Feature\ActivityLog;

use App\Http\Requests\Api\V1\FCH\Common\ActivityLog\StoreActivityLogRequest;
use App\Models\ActivityLog\ActivityLog;
use App\Models\Borrower\Borrower;
use App\Models\Partner\Partner;
use App\Models\User\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/**
 * The New Event form (log_type activity) labels its fields Reference, Date,
 * Summary (the `subject` column), Message (`description`, no *) and
 * Visibility. The Send Email form labels `subject` Subject and needs a message.
 */
#[CoversClass(StoreActivityLogRequest::class)]
class NewActivityValidationTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithPortalUsers;

    private User $partnerAdmin;
    private Borrower $borrower;

    protected function setUp(): void
    {
        parent::setUp();

        $this->makeHostPartner();
        $partner = $this->makeWhiteLabelPartner();
        $this->partnerAdmin = $this->makePartnerAdmin($partner);
        $this->borrower = $this->makeBorrower($partner);
    }

    /**
     * Defect (BUG-019): an empty New Event said "Subject is required." for the
     * field labelled Summary, and "Message is required." for a field with no *.
     */
    #[Test]
    public function an_empty_new_event_names_the_fields_the_form_marks_required(): void
    {
        $this->actingAsUser($this->partnerAdmin);

        $response = $this->postJson('/api/v1/common/activity-logs', ['log_type' => ActivityLog::TYPE_ACTIVITY, 'borrower_id' => $this->borrower->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors([
                'reference' => __('activity_log.reference_required'),
                'activity_date' => __('activity_log.activity_date_required'),
                'subject' => __('activity_log.summary_required'),
                'visibility',
            ]);

        $response->assertJsonMissingValidationErrors('description');
    }

    /** BUG-019: Message is optional on a New Event; without one, no message is stored. */
    #[Test]
    public function a_new_event_is_created_without_a_message(): void
    {
        $this->actingAsUser($this->partnerAdmin);

        $this->postJson('/api/v1/common/activity-logs', [
            'log_type' => ActivityLog::TYPE_ACTIVITY,
            'borrower_id' => $this->borrower->id,
            'reference' => 'Call',
            'activity_date' => now()->toDateTimeString(),
            'subject' => 'QA-TEST Run1 note',
            'visibility' => ActivityLog::VISIBILITY_PARTNER,
        ])->assertSuccessful();

        $log = ActivityLog::where('log_name', ActivityLog::TYPE_ACTIVITY)->latest('id')->firstOrFail();
        $this->assertSame('', $log->description);
        $this->assertSame('QA-TEST Run1 note', $log->properties['subject']);
    }

    /** An email keeps its own wording: Subject, and a message is required. */
    #[Test]
    public function an_empty_email_still_needs_a_subject_and_a_message(): void
    {
        $this->actingAsUser($this->partnerAdmin);

        $this->postJson('/api/v1/common/activity-logs', ['log_type' => ActivityLog::TYPE_EMAIL, 'borrower_id' => $this->borrower->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors([
                'subject' => __('activity_log.subject_required'),
                'description' => __('activity_log.description_required'),
            ]);
    }
}
