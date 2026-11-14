<?php

namespace Tests\Feature\Partner;

use App\Models\Appointment\Appointment;
use App\Models\Borrower\Borrower;
use App\Models\Invoice\Invoice;
use App\Models\Partner\Partner;
use App\Models\SupportTicket\SupportTicket;
use App\Models\User\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/**
 * BUG-173: the partner profile header cards count what the partner has now -
 * a deleted (soft-deleted) client, invoice, ticket or meeting is not counted,
 * so Total Clients matches the Client List tab.
 */
class PartnerProfileStatsTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithPortalUsers;

    private User $hostAdmin;
    private Partner $partner;
    private User $partnerAdmin;
    /** A user of another partner: schedules the meetings that are not this partner's users'. */
    private User $outsider;

    protected function setUp(): void
    {
        parent::setUp();

        $this->hostAdmin = $this->makePartnerAdmin($this->makeHostPartner());
        $this->partner = $this->makeWhiteLabelPartner();
        $this->partnerAdmin = $this->makePartnerAdmin($this->partner);
        $this->outsider = $this->makePartnerAdmin($this->makeWhiteLabelPartner());
    }

    private function stats(): array
    {
        $this->actingAsUser($this->hostAdmin);

        return $this->postJson("/api/v1/admin/partners-profile-states-details-count/{$this->partner->id}")
            ->assertOk()
            ->json('data');
    }

    private function invoice(): Invoice
    {
        return Invoice::create([
            'invoice_id' => 'INV-' . uniqid(),
            'partner_id' => $this->partner->id,
            'entity_type' => Partner::class,
            'entity_id' => $this->partner->id,
            'customer_name' => $this->partner->full_name,
            'email' => $this->partner->email,
            'payment_amount' => 100,
            'currency' => 'USD',
            'status' => Invoice::STATUS_PAID,
            'type' => Invoice::INVOICE_TYPE_ENROLLMENT,
        ]);
    }

    private function ticket(): SupportTicket
    {
        return SupportTicket::create([
            'partner_id' => $this->partner->id,
            'title' => 'Help',
            'priority' => 'low',
            'client_name' => 'Client',
        ]);
    }

    private function appointment(array $attributes = []): Appointment
    {
        return Appointment::create(array_merge([
            'appointment_code' => 'APT-' . uniqid(),
            'subject' => 'Kick-off',
            'appointment_date' => now()->addDays(3),
            'timezone' => 'UTC',
            'duration_minutes' => 30,
            'status' => Appointment::STATUS_PENDING,
            'meeting_type' => array_key_first(Appointment::MEETING_TYPES),
            'scheduled_by' => $this->outsider->id,
            'entity_type' => Partner::class,
            'entity_id' => $this->partner->id,
        ], $attributes));
    }

    #[Test]
    public function total_clients_does_not_count_a_deleted_client(): void
    {
        $this->makeBorrower($this->partner)->delete();
        $this->makeBorrower($this->partner);
        $this->makeBorrower($this->makeWhiteLabelPartner());

        $this->assertSame(1, (int) $this->stats()['total_clients']);
    }

    /** Archived clients leave the working list (Clients menu, "Active Only"), so the card leaves them out too. */
    #[Test]
    public function total_clients_does_not_count_an_archived_client(): void
    {
        $this->makeBorrower($this->partner)->archive();
        $this->makeBorrower($this->partner);

        $this->assertSame(1, (int) $this->stats()['total_clients']);
    }

    #[Test]
    public function the_other_cards_do_not_count_deleted_rows(): void
    {
        $this->invoice()->delete();
        $this->invoice();
        $this->ticket()->delete();
        $this->ticket();
        $this->appointment()->delete();
        $this->appointment();

        $stats = $this->stats();

        $this->assertSame(1, (int) $stats['total_invoice']);
        $this->assertSame(1, (int) $stats['total_tickets']);
        $this->assertSame(1, (int) $stats['total_meetings']);
    }

    #[Test]
    public function meetings_still_count_those_scheduled_by_or_assigned_to_the_partners_users_once_each(): void
    {
        $otherBorrower = $this->makeBorrower($this->makeWhiteLabelPartner());
        $elsewhere = ['entity_type' => Borrower::class, 'entity_id' => $otherBorrower->id];

        $this->appointment(['scheduled_by' => $this->partnerAdmin->id]);           // own + scheduled: once
        $this->appointment($elsewhere + ['scheduled_by' => $this->partnerAdmin->id]);
        $this->appointment($elsewhere + ['assigned_for' => $this->partnerAdmin->id]);
        $this->appointment($elsewhere);                                              // not the partner's

        $this->assertSame(3, (int) $this->stats()['total_meetings']);
    }
}
