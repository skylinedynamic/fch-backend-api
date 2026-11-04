<?php

namespace Tests\Feature\CreditProfile;

use App\Models\Borrower\Borrower;
use App\Models\Borrower\BorrowerCreditProfile;
use App\Models\Borrower\BorrowerDocument;
use App\Models\Employee\Employee;
use App\Models\Partner\Partner;
use App\Models\User\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Passport\Passport;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Illuminate\Testing\TestResponse;

/**
 * BUG-016: the iSoftPull report is FCRA data. It is kept in its own private
 * folder (credit-reports/{borrower}/{uuid}.pdf) under a name that says nothing,
 * handed out only by a short-lived signed URL, and the server never fetches a
 * URL a client sends for it.
 */
class CreditReportPrivateStorageTest extends TestCase
{
    use RefreshDatabase;

    private Partner $host;
    private Borrower $borrower;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        $this->host = Partner::create([
            'is_host' => true,
            'status' => Partner::STATUS_ACTIVE,
            'partner_type' => Partner::PARTNER_TYPE_CERTIFIED,
            'company_name' => 'Four Corner Funding',
            'full_name' => 'Host Owner',
            'email' => 'host-' . uniqid() . '@example.test',
        ]);
        $this->borrower = $this->borrowerOf($this->host);

        Passport::actingAs(User::create([
            'name' => 'Staff',
            'email' => 'staff-' . uniqid() . '@example.test',
            'password' => bcrypt('password'),
            'status' => true,
            'user_type' => User::EMPLOYEE,
            'entity_type' => Employee::class,
            'entity_id' => 999,
            'partner_id' => $this->host->id,
        ]), [], 'api');
    }

    private function borrowerOf(Partner $partner): Borrower
    {
        return Borrower::create([
            'partner_id' => $partner->id,
            'status' => Borrower::STATUS_ACTIVE,
            'first_name' => 'Odessa',
            'last_name' => 'Mccall',
            'email' => 'borrower-' . uniqid() . '@example.test',
        ]);
    }

    private function reportPdf(): UploadedFile
    {
        $stream = "BT /F1 12 Tf 72 720 Td (Name: Odessa Mccall) Tj 0 -20 Td (SSN: XXX-XX-6789) Tj 0 -20 Td (FICO Score: 712) Tj ET";
        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>',
            "<< /Length " . strlen($stream) . " >>\nstream\n{$stream}\nendstream",
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
        ];
        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $i => $object) {
            $offsets[] = strlen($pdf);
            $pdf .= ($i + 1) . " 0 obj\n{$object}\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 " . (count($objects) + 1) . "\n0000000000 65535 f \n";
        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }
        $pdf .= "trailer\n<< /Size " . (count($objects) + 1) . " /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF";

        return UploadedFile::fake()->createWithContent('isoftpull-report.pdf', $pdf);
    }

    private function upload(?Borrower $borrower = null): array
    {
        return $this->postJson('/api/v1/common/borrowers/parse-credit-profile', [
            'borrower_id' => ($borrower ?? $this->borrower)->id,
            'pdf' => $this->reportPdf(),
        ])->assertOk()->json('data');
    }

    private function save(array $extra): TestResponse
    {
        return $this->postJson('/api/v1/common/borrowers/save-credit-profile', [
            'borrower_id' => $this->borrower->id,
            'metrics' => ['fico_score' => 712],
        ] + $extra);
    }

    #[Test]
    public function an_uploaded_report_is_kept_privately_in_its_own_folder_under_a_name_that_says_nothing(): void
    {
        $data = $this->upload();

        $path = BorrowerDocument::findOrFail($data['document_id'])->getRawOriginal('file_path');
        $this->assertMatchesRegularExpression("#^credit-reports/{$this->borrower->id}/[0-9a-f-]{36}\.pdf$#", $path);
        $this->assertTrue(Storage::disk(config('files.private_folders.credit-reports'))->exists($path));
        $this->assertSame([], Storage::disk('public')->allFiles());

        // What the page gets back is a signed, short-lived URL - never /storage.
        $this->assertStringContainsString('/private-files/credit-reports/', $data['pdf_url']);
        $this->assertStringContainsString('signature=', $data['pdf_url']);
        $this->assertStringNotContainsString('/storage/', $data['pdf_url']);
    }

    #[Test]
    public function the_saved_profile_points_at_the_uploaded_report_not_at_a_url_the_client_sent(): void
    {
        $data = $this->upload();

        $this->save(['document_id' => $data['document_id'], 'pdf_url' => 'http://169.254.169.254/latest/meta-data/'])->assertOk();

        $profile = BorrowerCreditProfile::where('borrower_id', $this->borrower->id)->firstOrFail();
        $this->assertSame(BorrowerDocument::findOrFail($data['document_id'])->getRawOriginal('file_path'), $profile->getRawOriginal('pdf_url'));
    }

    #[Test]
    public function reading_the_profile_never_fetches_a_url(): void
    {
        Http::fake();
        $data = $this->upload();
        $this->save(['document_id' => $data['document_id']])->assertOk();
        // A profile saved before, pointing anywhere at all.
        BorrowerCreditProfile::where('borrower_id', $this->borrower->id)->update(['pdf_url' => 'http://169.254.169.254/latest/meta-data/']);

        $this->getJson("/api/v1/common/borrowers/get-credit-profile/{$this->borrower->id}")->assertOk();

        Http::assertNothingSent();
    }

    #[Test]
    public function the_profile_hands_out_its_report_and_a_signed_url(): void
    {
        $data = $this->upload();
        $this->save(['document_id' => $data['document_id']])->assertOk();

        $profile = $this->getJson("/api/v1/common/borrowers/get-credit-profile/{$this->borrower->id}")->assertOk()->json('data');

        $this->assertStringStartsWith('%PDF', base64_decode($profile['pdf']));
        $this->assertStringContainsString('/private-files/credit-reports/', $profile['pdf_url']);
        $this->get($profile['pdf_url'])->assertOk();
    }

    #[Test]
    public function a_report_saved_before_under_its_public_url_is_still_read(): void
    {
        Storage::disk('uploads')->put("borrowers/{$this->borrower->id}/credit_report_1.pdf", '%PDF old report');
        BorrowerCreditProfile::create([
            'borrower_id' => $this->borrower->id,
            'fico_score' => 700,
            'pdf_url' => "http://127.0.0.1:8000/storage/borrowers/{$this->borrower->id}/credit_report_1.pdf",
        ]);

        $profile = $this->getJson("/api/v1/common/borrowers/get-credit-profile/{$this->borrower->id}")->assertOk()->json('data');

        $this->assertSame('%PDF old report', base64_decode($profile['pdf']));
        $this->assertStringContainsString('/private-files/borrowers/', $profile['pdf_url']);
    }

    #[Test]
    public function another_clients_report_cannot_be_attached(): void
    {
        $other = $this->borrowerOf($this->host);
        $theirs = $this->upload($other);

        $this->save(['document_id' => $theirs['document_id']])->assertStatus(422);
    }
}
