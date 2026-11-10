<?php

namespace Tests\Unit\Calendly;

use App\Models\Appointment\Appointment;
use App\Services\Global\Calendly\CalendlyEventMapper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A Calendly scheduled event and its invitee, as the appointment columns they
 * fill: time in UTC (appointments are stored in UTC - BUG-080), the length,
 * the status, where it happens and the invitee's own links.
 */
#[CoversClass(CalendlyEventMapper::class)]
class CalendlyEventMapperTest extends TestCase
{
    private function event(array $overrides = []): array
    {
        return array_merge([
            'uri' => 'https://api.calendly.com/scheduled_events/EV1',
            'name' => '30 Minute Meeting',
            'status' => 'active',
            'start_time' => '2026-11-20T15:00:00.000000Z',
            'end_time' => '2026-11-20T15:45:00.000000Z',
            'event_type' => 'https://api.calendly.com/event_types/ET1',
            'location' => ['type' => 'zoom', 'join_url' => 'https://zoom.us/j/1'],
        ], $overrides);
    }

    private function invitee(array $overrides = []): array
    {
        return array_merge([
            'uri' => 'https://api.calendly.com/scheduled_events/EV1/invitees/INV1',
            'email' => 'Jane.Doe@Example.test',
            'name' => 'Jane Doe',
            'status' => 'active',
            'timezone' => 'Asia/Dhaka',
            'cancel_url' => 'https://calendly.com/cancellations/INV1',
            'reschedule_url' => 'https://calendly.com/reschedulings/INV1',
        ], $overrides);
    }

    #[Test]
    public function an_active_event_becomes_a_confirmed_appointment_stored_in_utc(): void
    {
        $attributes = CalendlyEventMapper::attributes($this->event(), $this->invitee(), 'UTC');

        $this->assertSame([
            'source' => Appointment::SOURCE_CALENDLY,
            'calendly_event_uri' => 'https://api.calendly.com/scheduled_events/EV1',
            'calendly_invitee_uri' => 'https://api.calendly.com/scheduled_events/EV1/invitees/INV1',
            'calendly_event_type_uri' => 'https://api.calendly.com/event_types/ET1',
            'subject' => '30 Minute Meeting with Jane Doe',
            'appointment_date' => '2026-11-20 15:00:00',
            'timezone' => 'Asia/Dhaka',
            'duration_minutes' => 45,
            'status' => Appointment::STATUS_CONFIRMED,
            'location' => 'https://zoom.us/j/1',
            'join_url' => 'https://zoom.us/j/1',
            'invitee_name' => 'Jane Doe',
            'invitee_email' => 'jane.doe@example.test',
            'cancel_url' => 'https://calendly.com/cancellations/INV1',
            'reschedule_url' => 'https://calendly.com/reschedulings/INV1',
        ], $attributes);
    }

    #[Test]
    public function a_start_time_with_an_offset_is_turned_into_utc(): void
    {
        $attributes = CalendlyEventMapper::attributes(
            $this->event(['start_time' => '2026-11-20T10:00:00-05:00', 'end_time' => '2026-11-20T10:30:00-05:00']),
            $this->invitee(),
            'UTC'
        );

        $this->assertSame('2026-11-20 15:00:00', $attributes['appointment_date']);
        $this->assertSame(30, $attributes['duration_minutes']);
    }

    #[Test]
    public function a_cancelled_event_or_invitee_is_a_cancelled_appointment(): void
    {
        $this->assertSame(
            Appointment::STATUS_CANCELLED,
            CalendlyEventMapper::attributes($this->event(['status' => 'canceled']), $this->invitee(), 'UTC')['status']
        );
        $this->assertSame(
            Appointment::STATUS_CANCELLED,
            CalendlyEventMapper::attributes($this->event(), $this->invitee(['status' => 'canceled']), 'UTC')['status']
        );
    }

    #[Test]
    public function an_in_person_location_is_its_address_and_has_no_join_link(): void
    {
        $attributes = CalendlyEventMapper::attributes(
            $this->event(['location' => ['type' => 'physical', 'location' => '1 Main St, Springfield']]),
            $this->invitee(),
            'UTC'
        );

        $this->assertSame('1 Main St, Springfield', $attributes['location']);
        $this->assertNull($attributes['join_url']);
    }

    #[Test]
    public function an_unknown_invitee_zone_falls_back_to_the_given_zone(): void
    {
        $attributes = CalendlyEventMapper::attributes($this->event(), $this->invitee(['timezone' => 'Mars/Olympus']), 'America/New_York');

        $this->assertSame('America/New_York', $attributes['timezone']);
    }

    #[Test]
    public function an_event_without_an_invitee_keeps_the_event_name_and_no_invitee_details(): void
    {
        $attributes = CalendlyEventMapper::attributes($this->event(['location' => null]), null, 'UTC');

        $this->assertSame('30 Minute Meeting', $attributes['subject']);
        $this->assertNull($attributes['calendly_invitee_uri']);
        $this->assertNull($attributes['invitee_email']);
        $this->assertNull($attributes['location']);
        $this->assertSame('UTC', $attributes['timezone']);
    }

    #[Test]
    public function a_long_subject_is_cut_to_fit_the_column(): void
    {
        $attributes = CalendlyEventMapper::attributes($this->event(['name' => str_repeat('x', 300)]), $this->invitee(), 'UTC');

        $this->assertLessThanOrEqual(255, mb_strlen($attributes['subject']));
    }
}
