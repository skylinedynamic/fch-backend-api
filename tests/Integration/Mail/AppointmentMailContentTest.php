<?php

namespace Tests\Integration\Mail;

use App\Mail\Appointment\AppointmentNotification;
use App\Models\Appointment\Appointment;
use App\Models\Partner\Partner;
use App\Models\User\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/**
 * What the appointment emails say.
 *
 * Defect: R2 BUG-063 - "New Appointment Scheduled" showed only the date, time
 * slot, zone and "Scheduled By", with an empty block above them: no title, no
 * meeting type, no attendee, and no link to view it or add it to a calendar.
 */
#[CoversClass(AppointmentNotification::class)]
class AppointmentMailContentTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithPortalUsers;

    private Partner $host;
    private User $organiser;
    private User $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->host = $this->makeHostPartner();
        $this->host->update(['borrower_domain' => 'portal.example.test']);
        $this->organiser = $this->makePartnerAdmin($this->host, [], ['name' => 'Wanda Romanoff']);
        $this->client = $this->makeBorrowerUser($this->host, [], ['name' => 'Bo Rower']);
    }

    private function appointment(array $attributes = []): Appointment
    {
        $id = DB::table('appointments')->insertGetId(array_merge([
            'appointment_code' => 'APP-0042',
            'scheduled_by' => $this->organiser->id,
            'assigned_for' => $this->client->id,
            'subject' => 'QA-TEST R2 PP-APPT-03 branding mail',
            'meeting_type' => Appointment::MEETING_TYPE_BUSINESS_REVIEW,
            'description' => null,
            'appointment_date' => '2026-10-28 09:00:00',
            'duration_minutes' => 30,
            'timezone' => 'UTC',
            'status' => Appointment::STATUS_PENDING,
            'created_at' => now(),
            'updated_at' => now(),
        ], $attributes));

        return Appointment::findOrFail($id);
    }

    private function mail(Appointment $appointment, string $action): AppointmentNotification
    {
        return new AppointmentNotification($appointment, $action, ['company_name' => 'Four Corner Funding'], $this->host->id, $this->client);
    }

    #[Test]
    public function the_email_names_the_meeting_its_type_and_who_it_is_with_and_links_to_it(): void
    {
        $html = $this->mail($this->appointment(), 'created')->render();
        $text = strip_tags($html);

        $this->assertStringContainsString('QA-TEST R2 PP-APPT-03 branding mail', $text);
        $this->assertStringContainsString('Meeting Type', $text);
        $this->assertStringContainsString('Business Review', $text);
        $this->assertStringContainsString('With', $text);
        $this->assertStringContainsString('Wanda Romanoff', $text);
        $this->assertStringContainsString('Bo Rower', $text);
        $this->assertMatchesRegularExpression('#href="https?://portal\.example\.test/appointment"#', $html);
        $this->assertStringNotContainsString('description-card', $html);
    }

    #[Test]
    public function the_email_carries_a_calendar_file(): void
    {
        $attachments = $this->mail($this->appointment(), 'created')->attachments();

        $this->assertCount(1, $attachments);
        $ics = ($attachments[0]->as === 'appointment.ics') ? $this->icsOf($attachments[0]) : '';
        $this->assertStringContainsString('BEGIN:VCALENDAR', $ics);
        $this->assertStringContainsString('METHOD:REQUEST', $ics);
        $this->assertStringContainsString('DTSTART:20261028T090000Z', $ics);
        $this->assertStringContainsString('DTEND:20261028T093000Z', $ics);
        $this->assertStringContainsString('SUMMARY:QA-TEST R2 PP-APPT-03 branding mail', $ics);
    }

    #[Test]
    public function a_cancellation_cancels_the_calendar_entry(): void
    {
        $ics = $this->icsOf($this->mail($this->appointment(), 'deleted')->attachments()[0]);

        $this->assertStringContainsString('METHOD:CANCEL', $ics);
        $this->assertStringContainsString('STATUS:CANCELLED', $ics);
    }

    #[Test]
    public function staff_get_the_staff_portals_appointments_page(): void
    {
        $this->host->update(['partner_domain' => 'admin.example.test']);
        $mail = new AppointmentNotification($this->appointment(), 'created', [], $this->host->id, $this->organiser);

        $this->assertMatchesRegularExpression('#href="https?://admin\.example\.test/appointments"#', $mail->render());
    }

    private function icsOf($attachment): string
    {
        $contents = '';
        $attachment->attachWith(
            fn () => null,
            function ($data) use (&$contents) {
                $contents = $data();
            }
        );

        return $contents;
    }
}
