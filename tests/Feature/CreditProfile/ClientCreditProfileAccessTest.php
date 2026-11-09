<?php

namespace Tests\Feature\CreditProfile;

use App\Models\Borrower\Borrower;
use App\Models\Borrower\BorrowerCreditAddress;
use App\Models\Borrower\BorrowerCreditInquiry;
use App\Models\Borrower\BorrowerCreditProfile;
use App\Models\Borrower\BorrowerCreditTrade;
use App\Models\Borrower\BorrowerDocument;
use App\Models\CreditAuthorization\CreditAuthorizationConsent;
use App\Models\Employee\Employee;
use App\Models\Partner\Partner;
use App\Models\User\User;
use App\Services\Borrower\Profile\CreditAuthorizationConsentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Passport\Passport;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Client > Credit Profile. Only the host partner's team uploads an iSoftPull
 * report, saves it, or removes a client's credit profile; every other partner
 * may only read its own client's profile.
 */
class ClientCreditProfileAccessTest extends TestCase
{
    use RefreshDatabase;

    private Partner $host;
    private Partner $whiteLabel;
    private Borrower $borrower;

    protected function setUp(): void
    {
        parent::setUp();

        // Never the real public disk: the report is stored there.
        Storage::fake('public');

        $this->host = Partner::create([
            'is_host' => true,
            'status' => Partner::STATUS_ACTIVE,
            'partner_type' => Partner::PARTNER_TYPE_CERTIFIED,
            'company_name' => 'Four Corner Funding',
            'full_name' => 'Host Owner',
            'email' => 'host-' . uniqid() . '@example.test',
        ]);

        $this->whiteLabel = Partner::create([
            'is_host' => false,
            'status' => Partner::STATUS_ACTIVE,
            'partner_type' => Partner::PARTNER_TYPE_WHITE_LABEL,
            'company_name' => 'Acme Capital',
            'full_name' => 'Acme Owner',
            'email' => 'acme-' . uniqid() . '@example.test',
        ]);

        $this->borrower = Borrower::create([
            'partner_id' => $this->whiteLabel->id,
            'status' => Borrower::STATUS_ACTIVE,
            'first_name' => 'Odessa',
            'last_name' => 'Mccall',
            'email' => 'borrower-' . uniqid() . '@example.test',
        ]);
    }

    private function user(Partner $partner, string $type = User::PARTNER): User
    {
        return User::create([
            'name' => 'User ' . uniqid(),
            'email' => 'user-' . uniqid() . '@example.test',
            'password' => bcrypt('password'),
            'status' => true,
            'user_type' => $type,
            'entity_type' => $type === User::EMPLOYEE ? Employee::class : Partner::class,
            'entity_id' => $type === User::EMPLOYEE ? 999 : $partner->id,
            'partner_id' => $partner->id,
        ]);
    }

    private function borrowerUser(): User
    {
        return User::create([
            'name' => 'Odessa Mccall',
            'email' => 'login-' . uniqid() . '@example.test',
            'password' => bcrypt('password'),
            'status' => true,
            'user_type' => User::BORROWER,
            'entity_type' => Borrower::class,
            'entity_id' => $this->borrower->id,
            // A borrower's partner_id is its partner's - the host's here, which
            // is exactly why "the user's partner is the host" alone is not enough.
            'partner_id' => $this->host->id,
        ]);
    }

    private function actingAs_(User $user): void
    {
        Passport::actingAs($user, [], 'api');
    }

    private function save(): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/v1/common/borrowers/save-credit-profile', [
            'borrower_id' => $this->borrower->id,
            'metrics' => ['fico_score' => 712, 'paydex_score' => 80],
            'personal_info' => ['name' => 'Odessa Mccall'],
            'addresses' => [['type' => 'CURRENT', 'street' => '1 Main St', 'city' => 'Austin', 'state' => 'TX', 'zip' => '78701']],
            'trade_accounts' => [['company' => 'Acme Bank', 'status' => 'OPEN']],
            'inquiries' => [['company' => 'Acme Bank', 'date' => '2026-09-01']],
        ]);
    }

    /** A saved profile with its children, document and stored report. */
    private function storedProfile(): BorrowerCreditProfile
    {
        Storage::disk('uploads')->put("borrowers/{$this->borrower->id}/credit_report_1.pdf", '%PDF-1.4 report');
        $document = BorrowerDocument::create([
            'borrower_id' => $this->borrower->id,
            'file_name' => 'report.pdf',
            'file_path' => 'credit_report_1.pdf',
            'status' => BorrowerDocument::STATUS_UPLOADED,
        ]);

        $profile = BorrowerCreditProfile::create([
            'borrower_id' => $this->borrower->id,
            'fico_score' => 712,
            'pdf_url' => "http://localhost/storage/borrowers/{$this->borrower->id}/credit_report_1.pdf",
            'document_id' => $document->id,
        ]);
        BorrowerCreditAddress::create(['borrower_credit_profile_id' => $profile->id, 'type' => 'CURRENT', 'street' => '1 Main St', 'city' => 'Austin', 'state' => 'TX', 'zip' => '78701']);
        BorrowerCreditTrade::create(['borrower_credit_profile_id' => $profile->id, 'company' => 'Acme Bank', 'status' => 'OPEN']);
        BorrowerCreditInquiry::create(['borrower_credit_profile_id' => $profile->id, 'company' => 'Acme Bank']);

        $this->borrower->forceFill(['fico_score' => 712, 'paydex_score' => 80, 'credit_profile_updated' => true])->save();

        return $profile;
    }

    // -- uploading and saving ------------------------------------------------

    #[Test]
    public function the_host_partner_saves_a_clients_credit_profile(): void
    {
        $this->actingAs_($this->user($this->host));

        $this->save()->assertOk();

        $this->assertSame(712, $this->borrower->refresh()->creditProfile->fico_score);
    }

    #[Test]
    public function the_host_partners_staff_save_it_too(): void
    {
        $this->actingAs_($this->user($this->host, User::EMPLOYEE));

        $this->save()->assertOk();
    }

    #[Test]
    public function another_partner_cannot_save_its_clients_credit_profile(): void
    {
        $this->actingAs_($this->user($this->whiteLabel));

        $this->save()->assertForbidden();

        $this->assertNull($this->borrower->refresh()->creditProfile);
        $this->assertFalse((bool) $this->borrower->credit_profile_updated);
    }

    #[Test]
    public function another_partner_cannot_upload_a_report(): void
    {
        $this->actingAs_($this->user($this->whiteLabel));

        $this->postJson('/api/v1/common/borrowers/parse-credit-profile', [
            'borrower_id' => $this->borrower->id,
            'pdf' => UploadedFile::fake()->create('report.pdf', 10, 'application/pdf'),
        ])->assertForbidden();

        $this->assertSame([], Storage::disk('uploads')->allFiles());
        $this->assertSame(0, BorrowerDocument::count());
    }

    #[Test]
    public function a_borrower_cannot_save_one_even_with_the_hosts_partner_id(): void
    {
        $this->actingAs_($this->borrowerUser());

        $this->save()->assertForbidden();
    }

    #[Test]
    public function another_partner_still_reads_its_clients_credit_profile(): void
    {
        $this->storedProfile();
        $this->actingAs_($this->user($this->whiteLabel));

        $this->getJson("/api/v1/common/borrowers/get-credit-profile/{$this->borrower->id}")
            ->assertOk()
            ->assertJsonPath('data.metrics.fico_score', 712);
    }

    #[Test]
    public function every_partner_is_told_whether_it_may_change_the_credit_profile(): void
    {
        $this->storedProfile();

        // The host's staff see every client (its owner account only its own).
        $this->actingAs_($this->user($this->host, User::EMPLOYEE));
        $this->getJson("/api/v1/common/borrowers/get-credit-profile/{$this->borrower->id}")
            ->assertJsonPath('data.can_manage', true);

        $this->actingAs_($this->user($this->whiteLabel));
        $this->getJson("/api/v1/common/borrowers/get-credit-profile/{$this->borrower->id}")
            ->assertJsonPath('data.can_manage', false);
    }

    // -- removing ------------------------------------------------------------

    #[Test]
    public function the_host_partner_removes_all_of_a_clients_credit_profile_data(): void
    {
        $profile = $this->storedProfile();
        $consent = app(CreditAuthorizationConsentService::class)->record(
            $this->borrower->id,
            Request::create('/borrower/credit-profile-request', 'POST', [], [], [], ['REMOTE_ADDR' => '203.0.113.42'])
        );
        $this->actingAs_($this->user($this->host));

        $this->deleteJson("/api/v1/common/borrowers/{$this->borrower->id}/credit-profile")->assertOk();

        $this->assertNull(BorrowerCreditProfile::find($profile->id));
        $this->assertSame(0, BorrowerCreditAddress::where('borrower_credit_profile_id', $profile->id)->count());
        $this->assertSame(0, BorrowerCreditTrade::where('borrower_credit_profile_id', $profile->id)->count());
        $this->assertSame(0, BorrowerCreditInquiry::where('borrower_credit_profile_id', $profile->id)->count());
        $this->assertNull(BorrowerDocument::find($profile->document_id));
        Storage::disk('uploads')->assertMissing("borrowers/{$this->borrower->id}/credit_report_1.pdf");

        $this->borrower->refresh();
        $this->assertNull($this->borrower->fico_score);
        $this->assertNull($this->borrower->paydex_score);
        $this->assertFalse((bool) $this->borrower->credit_profile_updated);

        // The client's authorisation is a compliance record (FCRA): it stays.
        $this->assertNotNull(CreditAuthorizationConsent::find($consent->id));
    }

    #[Test]
    public function another_partner_cannot_remove_it(): void
    {
        $profile = $this->storedProfile();
        $this->actingAs_($this->user($this->whiteLabel));

        $this->deleteJson("/api/v1/common/borrowers/{$this->borrower->id}/credit-profile")->assertForbidden();

        $this->assertNotNull(BorrowerCreditProfile::find($profile->id));
        Storage::disk('uploads')->assertExists("borrowers/{$this->borrower->id}/credit_report_1.pdf");
    }

    #[Test]
    public function removing_a_profile_that_is_not_there_is_not_found(): void
    {
        $this->actingAs_($this->user($this->host));

        $this->deleteJson("/api/v1/common/borrowers/{$this->borrower->id}/credit-profile")->assertNotFound();
    }
}
