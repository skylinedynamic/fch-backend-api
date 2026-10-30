<?php

namespace Tests\Integration\Application;

use App\Models\Borrower\Borrower;
use App\Models\Borrower\BorrowerDocType;
use App\Models\Borrower\BorrowerDocument;
use App\Models\Funding\FundingSequencing;
use App\Models\Partner\Partner;
use App\Services\FCH\Common\Funding\FundingQualificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/**
 * Defect: a funding option blocked by the borrower's bank statements (no
 * statements on file, or deposits / balance / NSF / negative days outside the
 * lender's limits) showed the borrower only "Missing Info" and raw figures -
 * nothing said the bank statements were the problem or what to do. A failed
 * banking check now carries a plain message and a link to upload statements.
 */
#[CoversClass(FundingQualificationService::class)]
class BankingReadinessMessageTest extends TestCase
{
    use InteractsWithPortalUsers;
    use RefreshDatabase;

    private Partner $partner;

    private FundingSequencing $funding;

    protected function setUp(): void
    {
        parent::setUp();

        $this->makeHostPartner();
        $this->partner = $this->makeWhiteLabelPartner();

        $lenderId = DB::table('lenders')->insertGetId([
            'name' => 'Mara Lloyd',
            'status' => 'Active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $sequenceId = DB::table('funding_sequencings')->insertGetId([
            'name' => 'Equipment Loans',
            'status' => 'Active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('lender_visibilities')->insert([
            'lender_id' => $lenderId,
            'funding_sequencing_id' => $sequenceId,
            'min_monthly_deposits' => 60000,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->funding = FundingSequencing::findOrFail($sequenceId);
    }

    private function borrower(array $attributes = []): Borrower
    {
        return $this->makeBorrower($this->partner, $attributes);
    }

    private function withBankStatements(Borrower $borrower, string $status = BorrowerDocument::STATUS_UPLOADED): Borrower
    {
        $type = BorrowerDocType::firstOrCreate(['name' => BorrowerDocType::BANK_STATEMENTS]);
        BorrowerDocument::create([
            'borrower_id' => $borrower->id,
            'document_type_id' => $type->id,
            'status' => $status,
            'file_name' => 'march.pdf',
            'file_path' => 'march.pdf',
        ]);

        return $borrower;
    }

    private function factor(Borrower $borrower, string $label): ?array
    {
        return collect(app(FundingQualificationService::class)->evaluateReadinessFactors($borrower, $this->funding->id))
            ->firstWhere('label', $label);
    }

    #[Test]
    public function deposits_below_the_lenders_minimum_say_the_bank_statements_failed(): void
    {
        $borrower = $this->withBankStatements($this->borrower(['average_monthly_deposits' => 5000]));

        $factor = $this->factor($borrower, 'Minimum Monthly Deposits');

        $this->assertSame('Failed', $factor['status']);
        $this->assertSame('banking', $factor['group']);
        $this->assertSame(__('funding_sequencing.bank_statements_failed'), $factor['message']);
        $this->assertSame('/documents', $factor['action']['path']);
        $this->assertSame(__('funding_sequencing.upload_bank_statements'), $factor['action']['label']);
    }

    #[Test]
    public function no_deposit_figures_at_all_say_the_bank_statements_failed(): void
    {
        $factor = $this->factor($this->borrower(), 'Minimum Monthly Deposits');

        $this->assertSame('Failed', $factor['status']);
        $this->assertSame(__('funding_sequencing.bank_statements_failed'), $factor['message']);
    }

    #[Test]
    public function deposits_that_meet_the_minimum_carry_no_message(): void
    {
        $borrower = $this->withBankStatements($this->borrower(['average_monthly_deposits' => 75000]));

        $factor = $this->factor($borrower, 'Minimum Monthly Deposits');

        $this->assertNotSame('Failed', $factor['status']);
        $this->assertArrayNotHasKey('message', $factor);
        $this->assertArrayNotHasKey('action', $factor);
    }

    #[Test]
    public function a_failed_check_outside_banking_carries_no_bank_statement_message(): void
    {
        DB::table('lender_visibilities')->update(['min_fico' => 700]);
        $borrower = $this->withBankStatements($this->borrower(['average_monthly_deposits' => 75000, 'fico_score' => 550]));

        $factor = $this->factor($borrower, 'Personal Credit');

        $this->assertSame('Failed', $factor['status']);
        $this->assertArrayNotHasKey('message', $factor);
    }

    /**
     * Defect: a failed Banking Activity check read "Banking Activity: Failed,
     * Required: Uploaded" - nothing said what was missing or why it failed.
     * It now gives the reason (not uploaded / rejected) and
     * what is required, with a message for that case.
     */
    #[Test]
    #[DataProvider('bankingActivityFailures')]
    public function a_failed_banking_activity_check_says_why_and_what_is_required(?string $documentStatus, string $reasonCode, string $reasonKey, string $messageKey): void
    {
        DB::table('funding_sequencings')->update(['readiness_factors' => json_encode(['Banking Activity'])]);
        $borrower = $this->borrower(['average_monthly_deposits' => 75000]);
        if ($documentStatus) {
            $this->withBankStatements($borrower, $documentStatus);
        }

        $factor = $this->factor($borrower, 'Banking Activity');

        $this->assertSame('Failed', $factor['status']);
        $this->assertSame($reasonCode, $factor['reason_code']);
        $this->assertSame(__($reasonKey, ['document' => BorrowerDocType::BANK_STATEMENTS]), $factor['reason']);
        $this->assertSame(__('funding_sequencing.bank_statements_requirement', ['document' => BorrowerDocType::BANK_STATEMENTS]), $factor['requirement']);
        $this->assertSame(__($messageKey), $factor['message']);
        $this->assertSame('/documents', $factor['action']['path']);
    }

    public static function bankingActivityFailures(): array
    {
        return [
            'nothing uploaded' => [null, 'not_uploaded', 'funding_sequencing.bank_statements_reason_not_uploaded', 'funding_sequencing.bank_statements_missing'],
            'rejected' => [BorrowerDocument::STATUS_REJECTED, 'rejected', 'funding_sequencing.bank_statements_reason_rejected', 'funding_sequencing.bank_statements_rejected'],
        ];
    }

    #[Test]
    public function uploaded_bank_statements_pass_banking_activity_with_no_reason(): void
    {
        DB::table('funding_sequencings')->update(['readiness_factors' => json_encode(['Banking Activity'])]);
        $borrower = $this->withBankStatements($this->borrower(['average_monthly_deposits' => 75000]));

        $factor = $this->factor($borrower, 'Banking Activity');

        $this->assertNotSame('Failed', $factor['status']);
        $this->assertArrayNotHasKey('reason', $factor);
        $this->assertArrayNotHasKey('message', $factor);
    }
    /**
     * Decision (product, 2026-10-15, was D14): bank statements Under Review
     * count, like Uploaded and Accepted. They failed Banking Activity before,
     * so a borrower lost eligibility the moment staff started reviewing them.
     */
    #[Test]
    public function bank_statements_under_review_pass_banking_activity(): void
    {
        DB::table('funding_sequencings')->update(['readiness_factors' => json_encode(['Banking Activity'])]);
        $borrower = $this->withBankStatements($this->borrower(['average_monthly_deposits' => 75000]), BorrowerDocument::STATUS_UNDER_REVIEW);

        $factor = $this->factor($borrower, 'Banking Activity');

        $this->assertNotSame('Failed', $factor['status']);
        $this->assertArrayNotHasKey('reason', $factor);
        $this->assertArrayNotHasKey('message', $factor);
    }
}
