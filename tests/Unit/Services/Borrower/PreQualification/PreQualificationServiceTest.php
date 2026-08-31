<?php

namespace Tests\Unit\Services\Borrower\PreQualification;

use App\Mail\PreQualificationSubmitted;
use App\Models\Borrower\Borrower;
use App\Models\Borrower\PreQualificationRequest;
use App\Models\Partner\Partner;
use App\Models\User\User;
use App\Services\Borrower\PreQualification\PreQualificationService;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class PreQualificationServiceTest extends TestCase
{
    protected PreQualificationService $service;

    protected Borrower $borrower;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        // See FundingApplicationServiceTest for why this is needed.
        Factory::guessFactoryNamesUsing(
            fn (string $modelName) => 'Database\\Factories\\'.class_basename($modelName).'Factory'
        );

        $this->service = new PreQualificationService;

        $this->borrower = Borrower::factory()->create();
        $this->user = User::factory()->borrower($this->borrower)->create();

        $this->actingAs($this->user);
    }

    public function test_personal_info_creates_an_on_hold_pre_qualification_request(): void
    {
        $record = $this->service->personalInfo([
            'first_name' => 'John',
            'last_name' => 'Doe',
            'email' => 'john@example.com',
            'phone_number' => '5551234567',
            'state_of_incorporation' => 'CA',
        ]);

        $this->assertDatabaseHas('pre_qualification_requests', [
            'id' => $record->id,
            'borrower_id' => $this->borrower->id,
            'partner_id' => $this->user->partner_id,
            'first_name' => 'John',
            'last_name' => 'Doe',
            'status' => PreQualificationRequest::ON_HOLD,
        ]);
    }

    public function test_business_info_updates_the_same_on_hold_request_started_in_personal_info_step(): void
    {
        $record = $this->service->personalInfo([
            'first_name' => 'John',
            'last_name' => 'Doe',
            'email' => 'john@example.com',
            'phone_number' => '5551234567',
            'state_of_incorporation' => 'CA',
        ]);

        $updated = $this->service->businessInfo([
            'id' => $record->id,
            'business_name' => 'Acme Inc',
            'industry_type' => 'Retail',
            'business_structure' => 'LLC',
            'monthly_revenue' => 10000,
            'annual_revenue' => 120000,
            'years_in_business' => '2-5 years',
        ]);

        $this->assertSame($record->id, $updated->id);
        $this->assertDatabaseHas('pre_qualification_requests', [
            'id' => $record->id,
            // personal info preserved
            'first_name' => 'John',
            // business info now set
            'business_name' => 'Acme Inc',
            'status' => PreQualificationRequest::ON_HOLD,
        ]);
        $this->assertDatabaseCount('pre_qualification_requests', 1);
    }

    public function test_funding_info_updates_the_same_request_and_keeps_it_on_hold(): void
    {
        $record = PreQualificationRequest::create([
            'borrower_id' => $this->borrower->id,
            'partner_id' => $this->user->partner_id,
            'status' => PreQualificationRequest::ON_HOLD,
            'first_name' => 'John',
        ]);

        $updated = $this->service->fundingInfo([
            'id' => $record->id,
            'funding_requested_amount' => 50000,
            'funding_urgency' => 'Within 30 days',
            'purpose_of_funding' => 'Expansion',
            'personal_credit_score' => '700-750',
            'estimated_business_credit_score' => '70-79',
            'existing_business_debt' => false,
        ]);

        $this->assertSame($record->id, $updated->id);
        $this->assertDatabaseHas('pre_qualification_requests', [
            'id' => $record->id,
            'funding_requested_amount' => 50000,
            'purpose_of_funding' => 'Expansion',
            'status' => PreQualificationRequest::ON_HOLD,
        ]);
    }

    public function test_submit_moves_an_on_hold_request_to_pending_and_emails_the_partner(): void
    {
        Mail::fake();

        $partner = Partner::factory()->create(['email' => 'partner@example.com']);
        $borrower = Borrower::factory()->create(['partner_id' => $partner->id]);
        $user = User::factory()->borrower($borrower)->create();
        $this->actingAs($user);

        $record = PreQualificationRequest::create([
            'borrower_id' => $borrower->id,
            'partner_id' => $partner->id,
            'status' => PreQualificationRequest::ON_HOLD,
        ]);

        $this->service->submit(['id' => $record->id]);

        $this->assertSame(PreQualificationRequest::PENDING, $record->fresh()->status);

        Mail::assertSent(PreQualificationSubmitted::class, function ($mail) use ($partner) {
            return $mail->hasTo($partner->email);
        });
    }

    public function test_submit_throws_when_no_matching_on_hold_request_exists_for_the_borrower(): void
    {
        $this->expectException(\Exception::class);

        $this->service->submit(['id' => 999999]);
    }

    public function test_submit_throws_when_the_request_belongs_to_a_different_borrower(): void
    {
        $otherBorrower = Borrower::factory()->create();
        $otherRecord = PreQualificationRequest::create([
            'borrower_id' => $otherBorrower->id,
            'status' => PreQualificationRequest::ON_HOLD,
        ]);

        $this->expectException(\Exception::class);

        // acting as $this->user / $this->borrower, not $otherBorrower
        $this->service->submit(['id' => $otherRecord->id]);
    }

    public function test_get_pre_qualification_by_id_excludes_on_hold_records(): void
    {
        $onHold = PreQualificationRequest::create([
            'borrower_id' => $this->borrower->id,
            'status' => PreQualificationRequest::ON_HOLD,
        ]);

        $result = $this->service->getPreQualificationById($onHold->id);

        $this->assertNull($result);
    }

    public function test_get_pre_qualification_by_id_returns_a_submitted_record_owned_by_the_borrower(): void
    {
        $pending = PreQualificationRequest::create([
            'borrower_id' => $this->borrower->id,
            'status' => PreQualificationRequest::PENDING,
        ]);

        $result = $this->service->getPreQualificationById($pending->id);

        $this->assertNotNull($result);
        $this->assertSame($pending->id, $result->id);
    }
}
