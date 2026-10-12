<?php

namespace Tests\Feature\Borrower;

use App\Http\Controllers\Api\V1\FCH\Common\Borrower\BorrowerController;
use App\Models\Borrower\Borrower;
use App\Models\Borrower\BorrowerCreditProfile;
use App\Models\Partner\Partner;
use App\Models\User\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/**
 * A borrower's credit profile - consumer report data, so FCRA material:
 * /common/borrowers/parse-credit-profile reads an uploaded report PDF,
 * /save-credit-profile stores what the reviewer confirmed, and
 * /get-credit-profile/{id} reads it back.
 */
#[CoversClass(BorrowerController::class)]
class BorrowerCreditProfileApiTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithPortalUsers;

    private Partner $partner;
    private Borrower $borrower;
    private User $partnerAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        $this->makeHostPartner();
        $this->partner = $this->makeWhiteLabelPartner();
        $this->borrower = $this->makeBorrower($this->partner);
        $this->partnerAdmin = $this->makePartnerAdmin($this->partner);
    }

    private function reportPdf(string $text = 'FICO SCORE 712 JOHN DOE 123 MAIN ST SSN XXX-XX-1234'): UploadedFile
    {
        $pdf = Pdf::loadHTML("<p>{$text}</p>")->output();

        return UploadedFile::fake()->createWithContent('report.pdf', $pdf);
    }

    private function savePayload(Borrower $borrower, array $overrides = []): array
    {
        return array_merge([
            'borrower_id' => $borrower->id,
            'metrics' => ['fico_score' => 712, 'paydex_score' => 80, 'credit_utilization' => 21.5],
            'personal_info' => ['name' => 'Bo Rower'],
            'addresses' => [['type' => 'CURRENT', 'street' => '1 Main St', 'city' => 'Austin', 'state' => 'TX', 'zip' => '73301']],
            'trade_accounts' => [['company' => 'Big Bank', 'status' => 'OPEN', 'balance' => 1200, 'limit' => 5000]],
            'inquiries' => [['company' => 'Lender Co', 'date' => '2026-01-15']],
        ], $overrides);
    }

    #[Test]
    public function saving_stores_the_profile_with_its_addresses_trades_and_inquiries(): void
    {
        $this->actingAsUser($this->partnerAdmin);

        $this->postJson('/api/v1/common/borrowers/save-credit-profile', $this->savePayload($this->borrower))->assertOk();

        $profile = BorrowerCreditProfile::where('borrower_id', $this->borrower->id)->firstOrFail();
        $this->assertSame(712, (int) $profile->fico_score);
        $this->assertSame(1, $profile->addresses()->count());
        $this->assertSame(1, $profile->trades()->count());
        $this->assertSame(1, $profile->inquiries()->count());

        $borrower = $this->borrower->fresh();
        $this->assertSame(712, (int) $borrower->fico_score);
        $this->assertTrue((bool) $borrower->credit_profile_updated);
    }

    #[Test]
    public function saving_again_replaces_the_previous_lines_instead_of_adding_to_them(): void
    {
        $this->actingAsUser($this->partnerAdmin);

        $this->postJson('/api/v1/common/borrowers/save-credit-profile', $this->savePayload($this->borrower))->assertOk();
        $this->postJson('/api/v1/common/borrowers/save-credit-profile', $this->savePayload($this->borrower, [
            'metrics' => ['fico_score' => 730],
            'trade_accounts' => [['company' => 'A'], ['company' => 'B']],
        ]))->assertOk();

        $this->assertSame(1, BorrowerCreditProfile::where('borrower_id', $this->borrower->id)->count());
        $profile = BorrowerCreditProfile::where('borrower_id', $this->borrower->id)->first();
        $this->assertSame(730, (int) $profile->fico_score);
        $this->assertSame(2, $profile->trades()->count());
    }

    #[Test]
    public function the_saved_profile_reads_back(): void
    {
        $this->actingAsUser($this->partnerAdmin);
        $this->postJson('/api/v1/common/borrowers/save-credit-profile', $this->savePayload($this->borrower))->assertOk();

        $this->getJson("/api/v1/common/borrowers/get-credit-profile/{$this->borrower->id}")
            ->assertOk()
            ->assertJsonPath('data.metrics.fico_score', 712);
    }

    #[Test]
    public function a_borrower_without_a_profile_reads_back_as_null(): void
    {
        $this->actingAsUser($this->partnerAdmin);

        $this->getJson("/api/v1/common/borrowers/get-credit-profile/{$this->borrower->id}")
            ->assertOk()
            ->assertJsonPath('data', null);
    }

    /**
     * Defect: save looked the borrower up with a bare find(), so any signed-in
     * user could overwrite any borrower's credit profile.
     */
    #[Test]
    public function another_partners_borrowers_profile_cannot_be_written(): void
    {
        $theirs = $this->makeBorrower($this->makeCertifiedPartner());
        $this->actingAsUser($this->partnerAdmin);

        $this->postJson('/api/v1/common/borrowers/save-credit-profile', $this->savePayload($theirs))->assertNotFound();

        $this->assertDatabaseMissing('borrower_credit_profiles', ['borrower_id' => $theirs->id]);
        $this->assertNull($theirs->fresh()->fico_score);
    }

    /**
     * Defect: both actions validated inside try/catch(\Exception), which also
     * caught the ValidationException - so bad input came back as a 500.
     */
    #[Test]
    public function saving_validates_the_borrower_and_the_section_shapes(): void
    {
        $this->actingAsUser($this->partnerAdmin);

        $this->postJson('/api/v1/common/borrowers/save-credit-profile', [])->assertStatus(422);
        $this->postJson('/api/v1/common/borrowers/save-credit-profile', ['borrower_id' => 999999])->assertStatus(422);
        $this->postJson('/api/v1/common/borrowers/save-credit-profile', $this->savePayload($this->borrower, ['metrics' => 'high']))
            ->assertStatus(422);
    }

    #[Test]
    public function parsing_a_report_returns_what_it_read_and_files_the_pdf_with_the_borrowers_documents(): void
    {
        $this->actingAsUser($this->partnerAdmin);

        $response = $this->post('/api/v1/common/borrowers/parse-credit-profile', [
            'borrower_id' => $this->borrower->id,
            'pdf' => $this->reportPdf(),
        ], ['Accept' => 'application/json'])->assertOk();

        $response->assertJsonStructure(['data' => ['extracted_data', 'pdf_url', 'document_id', 'pdf', 'mime']]);
        $this->assertDatabaseHas('borrower_documents', [
            'id' => $response->json('data.document_id'),
            'borrower_id' => $this->borrower->id,
        ]);
    }

    /**
     * Defect: parse also used a bare find(), so a report could be filed against
     * any borrower on the platform.
     */
    #[Test]
    public function another_partners_borrower_cannot_have_a_report_parsed_onto_them(): void
    {
        $theirs = $this->makeBorrower($this->makeCertifiedPartner());
        $this->actingAsUser($this->partnerAdmin);

        $this->post('/api/v1/common/borrowers/parse-credit-profile', [
            'borrower_id' => $theirs->id,
            'pdf' => $this->reportPdf(),
        ], ['Accept' => 'application/json'])->assertNotFound();

        $this->assertDatabaseMissing('borrower_documents', ['borrower_id' => $theirs->id]);
    }

    /**
     * Defect: the parse step logged the request, the first 5000 characters of
     * the report text and the parsed personal information - consumer report
     * data written to the application log.
     */
    #[Test]
    public function parsing_does_not_write_the_reports_contents_to_the_log(): void
    {
        $logged = [];
        Log::listen(function ($message) use (&$logged) {
            $logged[] = $message->message . ' ' . json_encode($message->context);
        });
        $this->actingAsUser($this->partnerAdmin);

        $this->post('/api/v1/common/borrowers/parse-credit-profile', [
            'borrower_id' => $this->borrower->id,
            'pdf' => $this->reportPdf('UNIQUEREPORTMARKER 123 MAIN ST'),
        ], ['Accept' => 'application/json'])->assertOk();

        $all = implode("\n", $logged);
        $this->assertStringNotContainsString('UNIQUEREPORTMARKER', $all);
        $this->assertStringNotContainsString('text_preview', $all);
        $this->assertStringNotContainsString('personal_info', $all);
    }

    #[Test]
    public function parsing_needs_a_pdf(): void
    {
        $this->actingAsUser($this->partnerAdmin);

        $this->post('/api/v1/common/borrowers/parse-credit-profile', [
            'borrower_id' => $this->borrower->id,
            'pdf' => UploadedFile::fake()->create('report.docx', 10),
        ], ['Accept' => 'application/json'])->assertStatus(422);
    }

    /**
     * Defect (O7): the borrower's score and "credit profile updated" flag were
     * saved before the transaction opened, so a failure part-way through left
     * the borrower claiming a profile whose lines were never written.
     */
    #[Test]
    public function a_failure_part_way_leaves_the_borrower_as_it_was(): void
    {
        $this->actingAsUser($this->partnerAdmin);
        \App\Models\Borrower\BorrowerCreditTrade::creating(function () {
            throw new \RuntimeException('trade write failed');
        });

        $this->postJson('/api/v1/common/borrowers/save-credit-profile', $this->savePayload($this->borrower))
            ->assertStatus(500);

        $borrower = $this->borrower->fresh();
        $this->assertNull($borrower->fico_score);
        $this->assertFalse((bool) $borrower->credit_profile_updated);
        $this->assertSame(0, BorrowerCreditProfile::where('borrower_id', $this->borrower->id)->count());
    }
}
