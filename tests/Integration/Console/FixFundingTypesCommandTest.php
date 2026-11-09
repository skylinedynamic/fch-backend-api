<?php

namespace Tests\Integration\Console;

use App\Console\Commands\FixFundingTypes;
use App\Models\Borrower\FundingApplication;
use App\Models\Borrower\PreQualificationRequest;
use App\Models\EmbedForm\EmbedFormSubmission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/**
 * funding-types:fix (request 2026-11-05): an existing funding application or
 * unconverted embed submission whose Preferred / Additional Funding Type is not
 * an Active lender product category gets the category with the same
 * meaningful words ("Line of Credit" -> "Business Line of Credit"). --dry-run
 * shows exactly that and writes nothing; a real run asks first (--force does
 * not). A name with no matching category, a converted submission and
 * pre-qualifications are left as they are.
 */
#[CoversClass(FixFundingTypes::class)]
class FixFundingTypesCommandTest extends TestCase
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

        foreach (['Term Loan', 'Business Line of Credit', 'SBA Financing'] as $name) {
            DB::table('lender_product_categories')->insert(['name' => $name, 'status' => 'Active', 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    #[Test]
    public function a_dry_run_shows_every_change_and_writes_nothing(): void
    {
        $id = $this->application('FUND-1', 'Line of Credit', ['SBA Loan', 'Real Estate']);
        $before = (array) DB::table('funding_applications')->find($id);

        $output = $this->fix(['--dry-run' => true]);

        $this->assertStringContainsString('Dry run: nothing will be changed.', $output);
        $this->assertMatchesRegularExpression('/FUND-1\s*\|\s*Preferred\s*\|\s*Line of Credit\s*\|\s*Business Line of Credit/', $output);
        $this->assertMatchesRegularExpression('/FUND-1\s*\|\s*Additional\s*\|\s*SBA Loan\s*\|\s*SBA Financing/', $output);
        $this->assertStringContainsString('Records to update: 1', $output);
        // No matching category: listed, left as it is.
        $this->assertMatchesRegularExpression('/FUND-1\s*\|\s*Additional\s*\|\s*Real Estate/', $output);
        $this->assertStringContainsString('Left as they are (no matching category): 1', $output);

        $this->assertEquals($before, (array) DB::table('funding_applications')->find($id));
    }

    #[Test]
    public function a_forced_run_puts_the_matching_category_in_place(): void
    {
        $id = $this->application('FUND-1', 'Line of Credit', ['SBA Loan', 'Real Estate', 'Term Loan']);
        $updatedAt = DB::table('funding_applications')->where('id', $id)->value('updated_at');

        $this->fix(['--force' => true]);

        $row = DB::table('funding_applications')->find($id);
        $this->assertSame('Business Line of Credit', $row->preferred_funding_type);
        // Each mapped in place; one with no match is kept as it is.
        $this->assertSame(['SBA Financing', 'Real Estate', 'Term Loan'], json_decode($row->additional_funding_types, true));
        // A data correction, not an edit: the application keeps its place in the lists.
        $this->assertEquals($updatedAt, $row->updated_at);
    }

    #[Test]
    public function an_additional_type_that_becomes_the_preferred_one_or_a_repeat_is_dropped(): void
    {
        $id = $this->application('FUND-1', 'Business Line of Credit', ['Line of Credit', 'SBA Loan', 'SBA Financing']);

        $this->fix(['--force' => true]);

        $this->assertSame(['SBA Financing'], json_decode(DB::table('funding_applications')->where('id', $id)->value('additional_funding_types'), true));
    }

    #[Test]
    public function unconverted_embed_submissions_are_fixed_and_converted_ones_left_alone(): void
    {
        $open = $this->submission('SBA Loan', ['Line of Credit'], EmbedFormSubmission::STATUS_NEW);
        $converted = $this->submission('SBA Loan', [], EmbedFormSubmission::STATUS_CONVERTED);

        $this->fix(['--force' => true]);

        $this->assertSame('SBA Financing', DB::table('embedded_form_submissions')->where('id', $open)->value('preferred_funding_type'));
        $this->assertSame(['Business Line of Credit'], json_decode(DB::table('embedded_form_submissions')->where('id', $open)->value('additional_funding_types'), true));
        $this->assertSame('SBA Loan', DB::table('embedded_form_submissions')->where('id', $converted)->value('preferred_funding_type'));
    }

    #[Test]
    public function pre_qualifications_and_records_already_on_categories_are_not_touched(): void
    {
        $fine = $this->application('FUND-2', 'Term Loan', ['Business Line of Credit']);
        $before = (array) DB::table('funding_applications')->find($fine);
        $preQualId = DB::table('pre_qualification_requests')->insertGetId([
            'borrower_id' => $this->borrowerId, 'partner_id' => $this->partnerId,
            'status' => PreQualificationRequest::PENDING, 'purpose_of_funding' => 'Equipment',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $output = $this->fix(['--force' => true]);

        $this->assertStringContainsString('Records to update: 0', $output);
        $this->assertEquals($before, (array) DB::table('funding_applications')->find($fine));
        $this->assertSame('Equipment', DB::table('pre_qualification_requests')->where('id', $preQualId)->value('purpose_of_funding'));
    }

    #[Test]
    public function it_asks_before_changing_anything(): void
    {
        $id = $this->application('FUND-1', 'Line of Credit', []);

        $this->artisan('funding-types:fix')
            ->expectsConfirmation('Update 1 record(s) to the matching category?', 'no')
            ->assertExitCode(0);
        $this->assertSame('Line of Credit', DB::table('funding_applications')->where('id', $id)->value('preferred_funding_type'));

        $this->artisan('funding-types:fix')
            ->expectsConfirmation('Update 1 record(s) to the matching category?', 'yes')
            ->assertExitCode(0);
        $this->assertSame('Business Line of Credit', DB::table('funding_applications')->where('id', $id)->value('preferred_funding_type'));
    }

    #[Test]
    public function a_second_run_finds_nothing_left_to_do(): void
    {
        $this->application('FUND-1', 'Line of Credit', ['SBA Loan']);

        $this->fix(['--force' => true]);

        $this->assertStringContainsString('Records to update: 0', $this->fix(['--dry-run' => true]));
    }

    /**
     * Additional Funding Types stored as something other than a JSON list (an
     * old row, a hand edit) is left exactly as it is - never replaced by an
     * empty list - and reported; the Preferred type is still fixed.
     */
    #[Test]
    public function additional_types_that_are_not_a_list_are_kept_as_they_are(): void
    {
        $id = $this->application('FUND-1', 'Line of Credit', []);
        DB::table('funding_applications')->where('id', $id)->update(['additional_funding_types' => 'SBA Loan, Term Loan']);

        $output = $this->fix(['--force' => true]);

        $this->assertMatchesRegularExpression('/FUND-1\s*\|\s*Additional\s*\|\s*SBA Loan, Term Loan/', $output);
        $row = DB::table('funding_applications')->find($id);
        $this->assertSame('Business Line of Credit', $row->preferred_funding_type);
        $this->assertSame('SBA Loan, Term Loan', $row->additional_funding_types);
    }

    #[Test]
    public function a_record_that_cannot_be_updated_is_reported_and_the_rest_still_are(): void
    {
        $stuck = $this->application('FUND-1', 'Line of Credit', []);
        $other = $this->application('FUND-2', 'SBA Loan', []);
        // The database refuses this one record's update.
        DB::statement("CREATE TRIGGER refuse_fund_1 BEFORE UPDATE ON funding_applications WHEN OLD.id = {$stuck} BEGIN SELECT RAISE(ABORT, 'refused'); END");
        Log::spy();

        $output = $this->fix(['--force' => true]);

        $this->assertStringContainsString('FUND-1: not updated', $output);
        $this->assertStringContainsString('Updated: 1', $output);
        $this->assertSame('Line of Credit', DB::table('funding_applications')->where('id', $stuck)->value('preferred_funding_type'));
        $this->assertSame('SBA Financing', DB::table('funding_applications')->where('id', $other)->value('preferred_funding_type'));
        Log::shouldHaveReceived('error')->once();
    }

    /** @param  array<string, mixed>  $options */
    private function fix(array $options): string
    {
        $this->assertSame(0, Artisan::call('funding-types:fix', $options));

        return Artisan::output();
    }

    private function application(string $number, ?string $preferred, array $additional): int
    {
        return DB::table('funding_applications')->insertGetId([
            'application_number' => $number,
            'borrower_id' => $this->borrowerId,
            'partner_id' => $this->partnerId,
            'status' => FundingApplication::DRAFT,
            'preferred_funding_type' => $preferred,
            'additional_funding_types' => json_encode($additional),
            'created_at' => now()->subDay(),
            'updated_at' => now()->subDay(),
        ]);
    }

    private function submission(?string $preferred, array $additional, string $status): int
    {
        return DB::table('embedded_form_submissions')->insertGetId([
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
}
