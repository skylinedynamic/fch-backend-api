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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Passport\Passport;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The iSoftPull report uploaded on Client > Credit Profile is kept as a
 * borrower document, but it is the credit profile's - not one of the client's
 * documents. It stays out of the Documents lists, and removing the credit
 * profile removes that one document and no other.
 */
class CreditReportDocumentTest extends TestCase
{
    use RefreshDatabase;

    private Partner $host;
    private Borrower $borrower;

    protected function setUp(): void
    {
        parent::setUp();

        // Never the real public disk.
        Storage::fake('public');

        $this->host = Partner::create([
            'is_host' => true,
            'status' => Partner::STATUS_ACTIVE,
            'partner_type' => Partner::PARTNER_TYPE_CERTIFIED,
            'company_name' => 'Four Corner Funding',
            'full_name' => 'Host Owner',
            'email' => 'host-' . uniqid() . '@example.test',
        ]);

        $this->borrower = Borrower::create([
            'partner_id' => $this->host->id,
            'status' => Borrower::STATUS_ACTIVE,
            'first_name' => 'Odessa',
            'last_name' => 'Mccall',
            'email' => 'borrower-' . uniqid() . '@example.test',
        ]);
    }

    private function signInHostStaff(): void
    {
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

    private function signInBorrower(): void
    {
        Passport::actingAs(User::create([
            'name' => 'Odessa Mccall',
            'email' => 'login-' . uniqid() . '@example.test',
            'password' => bcrypt('password'),
            'status' => true,
            'user_type' => User::BORROWER,
            'entity_type' => Borrower::class,
            'entity_id' => $this->borrower->id,
            'partner_id' => $this->host->id,
        ]), [], 'api');
    }

    private function document(string $name, bool $creditReport = false): BorrowerDocument
    {
        $document = BorrowerDocument::create([
            'borrower_id' => $this->borrower->id,
            'file_name' => $name,
            'file_path' => $name,
            'status' => BorrowerDocument::STATUS_UPLOADED,
        ]);
        $document->forceFill(['is_credit_report' => $creditReport])->save();

        return $document;
    }

    /** A real, one-page PDF with selectable text, as iSoftPull sends. */
    private function reportPdf(): UploadedFile
    {
        $stream = "BT /F1 12 Tf 72 720 Td (Name: Odessa Mccall) Tj 0 -20 Td (SSN: XXX-XX-6789) Tj ET";
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

    #[Test]
    public function an_uploaded_report_is_kept_as_the_clients_credit_report_document(): void
    {
        $this->signInHostStaff();

        $documentId = $this->postJson('/api/v1/common/borrowers/parse-credit-profile', [
            'borrower_id' => $this->borrower->id,
            'pdf' => $this->reportPdf(),
        ])->assertOk()->json('data.document_id');

        $document = BorrowerDocument::findOrFail($documentId);
        $this->assertTrue((bool) $document->is_credit_report);
        $this->assertSame('isoftpull-report.pdf', $document->file_name);
    }

    #[Test]
    public function the_clients_documents_tab_leaves_the_credit_report_out(): void
    {
        $this->document('bank-statement.pdf');
        $this->document('credit_report_1.pdf', creditReport: true);
        $this->signInHostStaff();

        $names = collect($this->postJson("/api/v1/common/borrowers-documents/{$this->borrower->id}")
            ->assertOk()
            ->json('data'))
            ->pluck('document_name');

        $this->assertContains('bank-statement.pdf', $names);
        $this->assertNotContains('credit_report_1.pdf', $names);
    }

    #[Test]
    public function the_borrowers_own_documents_leave_the_credit_report_out(): void
    {
        $this->document('bank-statement.pdf');
        $this->document('credit_report_1.pdf', creditReport: true);
        $this->signInBorrower();

        $names = collect($this->getJson('/api/v1/borrower/documents')->assertOk()->json('data'))
            ->flatten(1)
            ->pluck('file_name');

        $this->assertContains('bank-statement.pdf', $names);
        $this->assertNotContains('credit_report_1.pdf', $names);
    }

    #[Test]
    public function removing_the_credit_profile_removes_its_report_and_no_other_document(): void
    {
        $statement = $this->document('bank-statement.pdf');
        $olderReport = $this->document('credit_report_1.pdf', creditReport: true);
        $report = $this->document('credit_report_2.pdf', creditReport: true);
        BorrowerCreditProfile::create(['borrower_id' => $this->borrower->id, 'document_id' => $report->id]);
        $this->signInHostStaff();

        $this->deleteJson("/api/v1/common/borrowers/{$this->borrower->id}/credit-profile")->assertOk();

        $this->assertNull(BorrowerDocument::withTrashed()->find($report->id));
        $this->assertNotNull(BorrowerDocument::find($statement->id));
        $this->assertNotNull(BorrowerDocument::find($olderReport->id));
    }

    #[Test]
    public function reports_uploaded_before_are_marked_by_the_migration(): void
    {
        $statement = $this->document('bank-statement.pdf');
        $linked = $this->document('linked-report.pdf');
        $named = $this->document('credit_report_1760000000.pdf');
        BorrowerCreditProfile::create(['borrower_id' => $this->borrower->id, 'document_id' => $linked->id]);
        DB::table('borrower_documents')->update(['is_credit_report' => false]);

        $migration = require database_path('migrations/2026_10_18_000000_add_is_credit_report_to_borrower_documents_table.php');
        $migration->backfill();

        $this->assertTrue((bool) $linked->refresh()->is_credit_report);
        $this->assertTrue((bool) $named->refresh()->is_credit_report);
        $this->assertFalse((bool) $statement->refresh()->is_credit_report);
    }
}
