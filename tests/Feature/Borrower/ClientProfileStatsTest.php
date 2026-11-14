<?php

namespace Tests\Feature\Borrower;

use App\Models\Appointment\Appointment;
use App\Models\Borrower\Borrower;
use App\Models\Borrower\BorrowerDocument;
use App\Models\Borrower\PreQualificationRequest;
use App\Models\Invoice\Invoice;
use App\Models\User\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/**
 * BUG-155: the client profile header cards count what the client has now -
 * deleted (soft-deleted) rows are not counted, and the Documents card counts
 * the same files the Documents tab lists (credit reports are not in it).
 */
class ClientProfileStatsTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithPortalUsers;

    private Borrower $borrower;
    private User $partnerAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->makeHostPartner();
        $partner = $this->makeWhiteLabelPartner();
        $this->borrower = $this->makeBorrower($partner);
        $this->partnerAdmin = $this->makePartnerAdmin($partner);
    }

    private function stats(): array
    {
        $this->actingAsUser($this->partnerAdmin);

        return $this->postJson("/api/v1/common/borrowers-profile-states-details-count/{$this->borrower->id}")
            ->assertOk()
            ->json('data');
    }

    private function document(array $attributes = []): BorrowerDocument
    {
        return BorrowerDocument::create(array_merge([
            'borrower_id' => $this->borrower->id,
            'file_name' => 'License.pdf',
            'file_path' => 'doc-' . uniqid() . '.pdf',
            'status' => BorrowerDocument::STATUS_UPLOADED,
        ], $attributes));
    }

    private function appointment(): Appointment
    {
        return Appointment::create([
            'appointment_code' => 'APT-' . uniqid(),
            'subject' => 'Kick-off',
            'appointment_date' => now()->addDays(3),
            'timezone' => 'UTC',
            'duration_minutes' => 30,
            'status' => Appointment::STATUS_PENDING,
            'meeting_type' => array_key_first(Appointment::MEETING_TYPES),
            'scheduled_by' => $this->partnerAdmin->id,
            'entity_type' => Borrower::class,
            'entity_id' => $this->borrower->id,
        ]);
    }

    private function invoice(): Invoice
    {
        return Invoice::create([
            'invoice_id' => 'INV-' . uniqid(),
            'partner_id' => $this->borrower->partner_id,
            'borrower_id' => $this->borrower->id,
            'entity_type' => Borrower::class,
            'entity_id' => $this->borrower->id,
            'customer_name' => $this->borrower->full_name,
            'email' => $this->borrower->email,
            'payment_amount' => 100,
            'currency' => 'USD',
            'status' => Invoice::STATUS_PAID,
            'type' => Invoice::INVOICE_TYPE_ENROLLMENT,
        ]);
    }

    private function preQualification(): PreQualificationRequest
    {
        return PreQualificationRequest::create([
            'borrower_id' => $this->borrower->id,
            'partner_id' => $this->borrower->partner_id,
        ]);
    }

    #[Test]
    public function the_documents_card_does_not_count_a_deleted_document(): void
    {
        $this->document()->delete();
        $this->document();

        $this->assertSame(1, (int) $this->stats()['total_documents']);
    }

    #[Test]
    public function the_documents_card_counts_what_the_documents_tab_lists(): void
    {
        $this->document();
        // Not fillable - set the way the credit report upload sets it.
        $this->document()->forceFill(['is_credit_report' => true])->save();

        $this->assertSame(1, (int) $this->stats()['total_documents']);
    }

    #[Test]
    public function the_other_cards_do_not_count_deleted_rows(): void
    {
        $this->appointment()->delete();
        $this->appointment();
        $this->invoice()->delete();
        $this->invoice();
        $this->preQualification()->delete();
        $this->preQualification();

        $stats = $this->stats();

        $this->assertSame(1, (int) $stats['total_meetings']);
        $this->assertSame(1, (int) $stats['total_invoices']);
        $this->assertSame(1, (int) $stats['total_prequalification']);
    }
}
