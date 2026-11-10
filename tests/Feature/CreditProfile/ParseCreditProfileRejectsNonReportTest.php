<?php

namespace Tests\Feature\CreditProfile;

use App\Http\Controllers\Api\V1\FCH\Common\Borrower\BorrowerController;
use App\Models\Borrower\Borrower;
use App\Models\Borrower\BorrowerCreditProfile;
use App\Models\Borrower\BorrowerDocument;
use App\Models\Employee\Employee;
use App\Models\Partner\Partner;
use App\Models\User\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Laravel\Passport\Passport;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * BUG-144: Client > Credit Profile > Choose PDF Report took any readable PDF
 * as a credit report - "parsed successfully" with every field empty, the PDF
 * stored - and a broken PDF showed the raw parser error ("Unable to find
 * startxref"). A PDF the parser finds nothing in is refused, and nothing of it
 * is kept.
 */
#[CoversClass(BorrowerController::class)]
class ParseCreditProfileRejectsNonReportTest extends TestCase
{
    use RefreshDatabase;

    private Borrower $borrower;

    /** The lines an iSoftPull report starts with. */
    private const REPORT = ['Name: Owner Client', "Score Model: FICO 8	Score: 712", 'Credit Limit: $5000'];

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        $host = Partner::create([
            'is_host' => true,
            'status' => Partner::STATUS_ACTIVE,
            'partner_type' => Partner::PARTNER_TYPE_CERTIFIED,
            'company_name' => 'Four Corner Funding',
            'full_name' => 'Host Owner',
            'email' => 'host-' . uniqid() . '@example.test',
        ]);

        $this->borrower = Borrower::create([
            'partner_id' => $host->id,
            'status' => Borrower::STATUS_ACTIVE,
            'first_name' => 'Owner',
            'last_name' => 'Client',
            'email' => 'borrower-' . uniqid() . '@example.test',
        ]);

        Passport::actingAs(User::create([
            'name' => 'Staff',
            'email' => 'staff-' . uniqid() . '@example.test',
            'password' => bcrypt('password'),
            'status' => true,
            'user_type' => User::EMPLOYEE,
            'entity_type' => Employee::class,
            'entity_id' => 999,
            'partner_id' => $host->id,
        ]), [], 'api');
    }

    /** A real, one-page PDF with selectable text, one line per array item. */
    private function pdfWithText(string|array $text, string $name): UploadedFile
    {
        $ops = 'BT /F1 12 Tf 72 720 Td';
        foreach ((array) $text as $i => $line) {
            $ops .= ($i ? ' 0 -16 Td' : '') . " ({$line}) Tj";
        }
        $stream = "{$ops} ET";
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

        return UploadedFile::fake()->createWithContent($name, $pdf);
    }

    private function parse(UploadedFile $pdf)
    {
        return $this->postJson('/api/v1/common/borrowers/parse-credit-profile', [
            'borrower_id' => $this->borrower->id,
            'pdf' => $pdf,
        ]);
    }

    private function assertNothingKept(): void
    {
        $this->assertSame(0, BorrowerDocument::where('borrower_id', $this->borrower->id)->count());
        $this->assertSame([], Storage::disk('public')->allFiles("borrowers/{$this->borrower->id}"));
        $this->assertNull(BorrowerCreditProfile::where('borrower_id', $this->borrower->id)->first());
        $this->assertFalse((bool) $this->borrower->fresh()->credit_profile_updated);
    }

    #[Test]
    public function a_pdf_that_is_not_a_credit_report_is_refused_and_nothing_is_kept(): void
    {
        $this->parse($this->pdfWithText('QA-TEST sample document - this is not a credit report', 'sample.pdf'))
            ->assertStatus(422)
            ->assertJsonPath('status', false)
            ->assertJsonPath('message', __('borrower.credit_profile_not_a_report'));

        $this->assertNothingKept();
    }

    /**
     * Defect: any PDF with a "Name:" in it passed as a credit report - a
     * commission invoice ("Payee Name: ...", an address and a phone) was
     * accepted on Client > Credit Profile and filed with the client's
     * documents. A name, an address or a phone is on every business document;
     * only credit data (a score, trade lines or inquiries) makes a credit report.
     */
    #[Test]
    public function an_invoice_with_a_name_address_and_phone_is_refused_and_nothing_is_kept(): void
    {
        $this->parse($this->pdfWithText([
            'Invoice No: #INV-00000000001', 'ADDRESS', 'Four Corner Funding', '100 Main St', 'Tampa, Florida 33601',
            'Phone: (555) 555-0100', 'PARTNER DETAILS', 'Pat Partner', 'TOTAL AMOUNT', '$1,250.00',
            'Payee Name: Pat Partner', 'Date Of Birth: 01/02/1980', 'Total Amount: $1,250.00', 'NOTES: Cheque Payment',
        ], 'invoice.pdf'))
            ->assertStatus(422)
            ->assertJsonPath('message', __('borrower.credit_profile_not_a_report'));

        $this->assertNothingKept();
    }

    /** A page that only explains FICO scores is not a report either. */
    #[Test]
    public function a_pdf_that_only_mentions_the_fico_range_is_refused(): void
    {
        $this->parse($this->pdfWithText(['About your credit', 'FICO scores range from 300 to 850', 'Higher is better'], 'guide.pdf'))
            ->assertStatus(422)
            ->assertJsonPath('message', __('borrower.credit_profile_not_a_report'));

        $this->assertNothingKept();
    }

    #[Test]
    public function a_broken_pdf_gets_a_plain_message_not_the_parsers_error(): void
    {
        $broken = UploadedFile::fake()->createWithContent('broken.pdf', "%PDF-1.4\nthis is not really a pdf\n%%EOF");

        $response = $this->parse($broken)
            ->assertStatus(422)
            ->assertJsonPath('message', __('borrower.credit_profile_unreadable_pdf'));

        $this->assertStringNotContainsString('startxref', $response->getContent());
        $this->assertNothingKept();
    }

    #[Test]
    public function a_credit_report_is_still_read_and_its_pdf_kept_but_not_saved_as_the_profile(): void
    {
        $this->parse($this->pdfWithText(self::REPORT, 'isoftpull-report.pdf'))
            ->assertOk()
            ->assertJsonPath('data.extracted_data.personal_info.name', 'Owner Client')
            ->assertJsonPath('data.extracted_data.metrics.fico_score', 712);

        // Kept for the host to review; the profile is only written on Save.
        $this->assertSame(1, BorrowerDocument::where('borrower_id', $this->borrower->id)->count());
        $this->assertNull(BorrowerCreditProfile::where('borrower_id', $this->borrower->id)->first());
    }

    /**
     * BUG-147: the borrower got "Your credit profile has been updated" twice -
     * on upload and on Save. Reading the PDF tells them nothing; only the
     * host's Save does (SaveCreditProfileNotifiesBorrowerTest).
     */
    #[Test]
    public function reading_a_report_tells_the_borrower_nothing(): void
    {
        Notification::fake();
        Mail::fake();

        $this->parse($this->pdfWithText(self::REPORT, 'isoftpull-report.pdf'))->assertOk();

        Notification::assertNothingSent();
        Mail::assertNothingOutgoing();
    }
}
