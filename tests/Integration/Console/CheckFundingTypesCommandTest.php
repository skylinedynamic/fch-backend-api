<?php

namespace Tests\Integration\Console;

use App\Console\Commands\CheckFundingTypes;
use App\Models\Borrower\FundingApplication;
use App\Models\Borrower\PreQualificationRequest;
use App\Models\EmbedForm\EmbedFormSubmission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/**
 * funding-types:check - a read-only report (dry run) of the existing data the
 * funding types change (2026-11-05: Preferred / Additional Funding Types are the
 * Active lender product categories) affects, so nothing is a surprise after
 * deploying it. It changes nothing.
 */
#[CoversClass(CheckFundingTypes::class)]
class CheckFundingTypesCommandTest extends TestCase
{
    use InteractsWithPortalUsers;
    use RefreshDatabase;

    private int $partnerId;

    private int $borrowerId;

    protected function setUp(): void
    {
        parent::setUp();

        $partner = $this->makeCertifiedPartner();
        $this->partnerId = $partner->id;
        $this->borrowerId = $this->makeBorrower($partner)->id;

        $this->category('Term Loan');
        $this->category('Business Line of Credit');
        $this->category('Equipment Financing');
        $this->category('Old Product', 'Inactive');
    }

    #[Test]
    public function it_lists_every_funding_type_that_is_not_an_active_category_and_suggests_one(): void
    {
        $this->application('FUND-1', 'Line of Credit', ['Term Loan', 'SBA Loan'], FundingApplication::DRAFT);
        $this->application('FUND-2', 'Term Loan', ['Business Line of Credit'], FundingApplication::DRAFT);
        $this->application('FUND-3', 'Old Product', [], FundingApplication::CLOSED_COMPLETED);

        $output = $this->report();

        $this->assertStringContainsString('Read only: nothing is changed.', $output);
        $this->assertStringContainsString('Funding applications with a funding type to re-choose: 2', $output);
        // FUND-1: an old name with a matching category, and one with none.
        $this->assertMatchesRegularExpression('/FUND-1\s*\|\s*Draft\s*\|\s*Yes\s*\|\s*Preferred\s*\|\s*Line of Credit\s*\|\s*Business Line of Credit/', $output);
        $this->assertMatchesRegularExpression('/FUND-1\s*\|\s*Draft\s*\|\s*Yes\s*\|\s*Additional\s*\|\s*SBA Loan\s*\|\s*\(no matching category\)/', $output);
        // FUND-3: a category made Inactive, past the editable statuses.
        $this->assertMatchesRegularExpression('/FUND-3\s*\|\s*Closed \(Completed\)\s*\|\s*No \(locked\)\s*\|\s*Preferred\s*\|\s*Old Product/', $output);
        // FUND-2 uses only Active categories.
        $this->assertStringNotContainsString('FUND-2', $output);
    }

    #[Test]
    public function it_lists_unconverted_embed_submissions_and_pre_qualification_purposes_no_category_matches(): void
    {
        $this->submission('SBA Loan', ['Line of Credit'], EmbedFormSubmission::STATUS_NEW);
        $this->submission('Hard Money', [], EmbedFormSubmission::STATUS_CONVERTED); // converted: its application is checked instead
        $this->preQual('Equipment');   // "Equipment Financing" finds it
        $this->preQual('Real Estate');  // no category finds it
        $this->preQual('Real Estate');

        $output = $this->report();

        $this->assertStringContainsString('Embed form submissions not converted yet with a funding type to re-choose: 1', $output);
        $this->assertMatchesRegularExpression('/Preferred\s*\|\s*SBA Loan/', $output);
        $this->assertMatchesRegularExpression('/Additional\s*\|\s*Line of Credit\s*\|\s*Business Line of Credit/', $output);
        $this->assertStringNotContainsString('Hard Money', $output);
        $this->assertStringContainsString('Pre-qualification purposes no funding type filter finds: 1 (2 pre-qualifications)', $output);
        $this->assertMatchesRegularExpression('/Real Estate\s*\|\s*2/', $output);
        $this->assertDoesNotMatchRegularExpression('/\|\s*Equipment\s*\|/', $output);
    }

    #[Test]
    public function it_lists_categories_lenders_cannot_be_reached_by(): void
    {
        $this->lenderIn('Old Product', 'Lender On Old');
        $this->lenderIn('Term Loan', 'Lender On Term');

        $output = $this->report();

        $this->assertStringContainsString('Inactive categories still used by lender rules: 1', $output);
        $this->assertMatchesRegularExpression('/Old Product\s*\|\s*Lender On Old/', $output);
        $this->assertStringContainsString('Active categories with no lender: 2', $output);
        $this->assertMatchesRegularExpression('/\|\s*Business Line of Credit\s*\|/', $output);
        $this->assertMatchesRegularExpression('/\|\s*Equipment Financing\s*\|/', $output);
    }

    #[Test]
    public function with_nothing_to_report_it_says_so(): void
    {
        $this->application('FUND-9', 'Term Loan', ['Business Line of Credit'], FundingApplication::DRAFT);
        $this->lenderIn('Term Loan', 'A');
        $this->lenderIn('Business Line of Credit', 'B');
        $this->lenderIn('Equipment Financing', 'C');

        $output = $this->report();

        $this->assertStringContainsString('Funding applications with a funding type to re-choose: 0', $output);
        $this->assertStringContainsString('Embed form submissions not converted yet with a funding type to re-choose: 0', $output);
        $this->assertStringContainsString('Inactive categories still used by lender rules: 0', $output);
        $this->assertStringContainsString('Active categories with no lender: 0', $output);
    }

    #[Test]
    public function it_changes_nothing(): void
    {
        $id = $this->application('FUND-1', 'Line of Credit', ['SBA Loan'], FundingApplication::DRAFT);
        $before = (array) DB::table('funding_applications')->find($id);

        $this->report();

        $this->assertEquals($before, (array) DB::table('funding_applications')->find($id));
    }

    private function report(): string
    {
        $this->assertSame(0, Artisan::call('funding-types:check'));

        return Artisan::output();
    }

    private function category(string $name, string $status = 'Active'): int
    {
        return DB::table('lender_product_categories')->insertGetId(['name' => $name, 'status' => $status, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function lenderIn(string $category, string $name): void
    {
        $lenderId = DB::table('lenders')->insertGetId(['name' => $name, 'status' => 'Active', 'created_at' => now(), 'updated_at' => now()]);
        $sequenceId = DB::table('funding_sequencings')->insertGetId(['name' => "{$name} sequence", 'status' => 'Active', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('lender_visibilities')->insert([
            'lender_id' => $lenderId,
            'funding_sequencing_id' => $sequenceId,
            'lender_product_category_id' => DB::table('lender_product_categories')->where('name', $category)->value('id'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function application(string $number, ?string $preferred, array $additional, string $status): int
    {
        return DB::table('funding_applications')->insertGetId([
            'application_number' => $number,
            'borrower_id' => $this->borrowerId,
            'partner_id' => $this->partnerId,
            'status' => $status,
            'preferred_funding_type' => $preferred,
            'additional_funding_types' => json_encode($additional),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function submission(?string $preferred, array $additional, string $status): void
    {
        DB::table('embedded_form_submissions')->insert([
            'submission_uid' => (string) Str::uuid(),
            'partner_id' => $this->partnerId,
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'email' => 'ada-' . Str::random(6) . '@example.test',
            'status' => $status,
            'preferred_funding_type' => $preferred,
            'additional_funding_types' => json_encode($additional),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function preQual(string $purpose): void
    {
        DB::table('pre_qualification_requests')->insert([
            'borrower_id' => $this->borrowerId,
            'partner_id' => $this->partnerId,
            'status' => PreQualificationRequest::PENDING,
            'purpose_of_funding' => $purpose,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
