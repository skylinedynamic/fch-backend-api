<?php

namespace Tests\Integration\Funding;

use App\Models\Borrower\Borrower;
use App\Models\FundingRequest\FundingRequest;
use App\Models\Lender\Lender;
use App\Services\FCH\Common\Funding\LenderMatchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/**
 * The Lender Information table's matching and scoring, against real lender
 * visibility rows. Moved unchanged out of FundingRequest::matched_lenders; a
 * loan application matches on several categories at once.
 */
#[CoversClass(LenderMatchService::class)]
class LenderMatchServiceTest extends TestCase
{
    use InteractsWithPortalUsers;
    use RefreshDatabase;

    private LenderMatchService $matcher;

    private Borrower $borrower;

    protected function setUp(): void
    {
        parent::setUp();

        $this->matcher = new LenderMatchService;
        $this->borrower = $this->makeBorrower($this->makeHostPartner(), [
            'fico_score' => 700,
            'paydex_score' => 70,
            'monthly_revenue' => 20000,
            'average_monthly_deposits' => 15000,
            'business_start_date' => now()->subYears(3)->toDateString(),
        ]);
    }

    #[Test]
    public function a_lender_meeting_every_requirement_is_an_excellent_match(): void
    {
        $this->lenderIn('Term Loan', 'Fundbox', ['min_fico' => 650, 'min_monthly_revenue' => 10000, 'min_tib_months' => 24, 'min_monthly_deposits' => 5000]);

        [$match] = $this->matcher->match($this->borrower, ['Term Loan']);

        $this->assertSame('Fundbox', $match['name']);
        $this->assertSame('Term Loan', $match['product_category']);
        $this->assertEquals(100, $match['score']);
        $this->assertSame('Excellent Match', $match['score_text']);
        $this->assertSame('4/4', $match['matches_fraction']);
        $this->assertSame(['fico' => true, 'business_credit' => true, 'revenue' => true, 'tib' => true, 'deposit' => true], $match['checks']);
    }

    #[Test]
    public function the_score_bands_follow_the_share_of_requirements_met(): void
    {
        $this->lenderIn('Term Loan', 'Good', ['min_fico' => 650, 'min_monthly_revenue' => 50000]); // 1 of 2
        $this->lenderIn('Term Loan', 'Low', ['min_fico' => 800, 'min_monthly_revenue' => 50000, 'min_tib_months' => 120]); // 0 of 3
        $this->lenderIn('Term Loan', 'Open'); // no requirements

        $byName = collect($this->matcher->match($this->borrower, ['Term Loan']))->keyBy('name');

        $this->assertSame('Good Match', $byName['Good']['score_text']);
        $this->assertSame('1/2', $byName['Good']['matches_fraction']);
        $this->assertSame('Low Match', $byName['Low']['score_text']);
        $this->assertFalse($byName['Low']['checks']['fico']);
        $this->assertSame('Perfect Match', $byName['Open']['score_text']);
        $this->assertSame('0/4', $byName['Open']['matches_fraction']);
    }

    #[Test]
    public function the_best_match_comes_first(): void
    {
        $this->lenderIn('Term Loan', 'Low', ['min_fico' => 800]);
        $this->lenderIn('Term Loan', 'High', ['min_fico' => 600]);

        $this->assertSame(['High', 'Low'], array_column($this->matcher->match($this->borrower, ['Term Loan']), 'name'));
    }

    /**
     * Request 2026-11-05: the highest match % first (100% at the top), whatever
     * the funding type. On an equal %, a lender of the Preferred Funding Type
     * (the first category given) comes before one of an Additional type.
     */
    #[Test]
    public function the_highest_match_comes_first_and_the_preferred_type_breaks_a_tie(): void
    {
        $this->lenderIn('Business Line of Credit', 'Additional 100', ['min_fico' => 600]);
        $this->lenderIn('Term Loan', 'Preferred 50', ['min_fico' => 600, 'min_monthly_revenue' => 50000]);
        $this->lenderIn('Term Loan', 'Preferred 100', ['min_fico' => 600]);
        $this->lenderIn('Business Line of Credit', 'Additional 0', ['min_fico' => 800]);

        $names = array_column($this->matcher->match($this->borrower, ['Term Loan', 'Business Line of Credit']), 'name');

        $this->assertSame(['Preferred 100', 'Additional 100', 'Preferred 50', 'Additional 0'], $names);
    }

    #[Test]
    public function several_categories_are_matched_and_a_lender_in_two_is_listed_once_at_its_best(): void
    {
        $both = $this->lenderIn('Term Loan', 'Both', ['min_fico' => 800]);
        $this->visibility($both, 'Business Line of Credit', ['min_fico' => 600]);
        $this->lenderIn('Business Line of Credit', 'Credit Only');

        $matches = collect($this->matcher->match($this->borrower, ['Term Loan', 'Business Line of Credit', 'Term Loan', '']));

        $this->assertCount(2, $matches);
        $this->assertSame('Business Line of Credit', $matches->firstWhere('name', 'Both')['product_category']);
        $this->assertEquals(100, $matches->firstWhere('name', 'Both')['score']);
    }

    #[Test]
    public function without_a_business_start_date_the_time_in_business_is_none(): void
    {
        $this->lenderIn('Term Loan', 'Six Months', ['min_tib_months' => 6]);
        $borrower = Borrower::whereKey($this->borrower->id)->firstOrFail()->forceFill(['business_start_date' => null]);

        [$match] = $this->matcher->match($borrower, ['Term Loan']);

        $this->assertFalse($match['checks']['tib']);
    }

    /**
     * The matcher still reads a "3 years" / "8 months" text when it gets one -
     * kept from FundingRequest unchanged. Borrower::years_in_business is always
     * a number worked out from the start date today, so only a borrower that
     * gives text reaches it (findings O77).
     */
    #[Test]
    public function a_years_in_business_text_is_read_as_months(): void
    {
        $this->lenderIn('Term Loan', 'Two Years', ['min_tib_months' => 24]);
        $this->lenderIn('Term Loan', 'Six Months', ['min_tib_months' => 6]);

        $years = $this->borrowerInBusinessFor('3 years');
        $months = $this->borrowerInBusinessFor('8 months');
        $unclear = $this->borrowerInBusinessFor('a while');

        $byYears = collect($this->matcher->match($years, ['Term Loan']))->keyBy('name');
        $byMonths = collect($this->matcher->match($months, ['Term Loan']))->keyBy('name');
        $byUnclear = collect($this->matcher->match($unclear, ['Term Loan']))->keyBy('name');

        $this->assertTrue($byYears['Two Years']['checks']['tib']);
        $this->assertFalse($byMonths['Two Years']['checks']['tib']);
        $this->assertTrue($byMonths['Six Months']['checks']['tib']);
        $this->assertFalse($byUnclear['Six Months']['checks']['tib']);
    }

    #[Test]
    public function an_unreadable_business_start_date_counts_as_no_time_in_business(): void
    {
        $this->lenderIn('Term Loan', 'Six Months', ['min_tib_months' => 6]);
        $borrower = Borrower::whereKey($this->borrower->id)->firstOrFail();
        $borrower->setRawAttributes(array_merge($borrower->getAttributes(), ['business_start_date' => 'not a date']));

        [$match] = $this->matcher->match($borrower, ['Term Loan']);

        $this->assertFalse($match['checks']['tib']);
    }

    private function borrowerInBusinessFor(string $text): Borrower
    {
        $borrower = new class extends Borrower
        {
            public string $inBusinessFor = '';

            public function getYearsInBusinessAttribute()
            {
                return $this->inBusinessFor;
            }
        };
        $borrower->setRawAttributes($this->borrower->getAttributes());
        $borrower->business_start_date = null;
        $borrower->inBusinessFor = $text;

        return $borrower;
    }

    #[Test]
    public function nothing_matches_without_a_borrower_a_category_or_a_known_category(): void
    {
        $this->lenderIn('Term Loan', 'Fundbox');

        $this->assertSame([], $this->matcher->match(null, ['Term Loan']));
        $this->assertSame([], $this->matcher->match($this->borrower, []));
        $this->assertSame([], $this->matcher->match($this->borrower, [null, '']));
        $this->assertSame([], $this->matcher->match($this->borrower, ['Unknown Category']));
    }

    #[Test]
    public function a_visibility_row_whose_lender_is_gone_is_skipped(): void
    {
        $gone = $this->lenderIn('Term Loan', 'Gone');
        DB::table('lenders')->where('id', $gone)->update(['deleted_at' => now()]);
        $this->lenderIn('Term Loan', 'Here');

        $this->assertSame(['Here'], array_column($this->matcher->match($this->borrower, ['Term Loan']), 'name'));
    }

    #[Test]
    public function the_funding_requests_matched_lenders_come_from_the_service(): void
    {
        $this->lenderIn('Term Loan', 'Fundbox', ['min_fico' => 650]);
        $request = (new FundingRequest)->forceFill(['funding_type' => 'Term Loan', 'borrower_id' => $this->borrower->id]);
        $request->setRelation('borrower', $this->borrower);

        $this->assertSame(
            $this->matcher->match($this->borrower, ['Term Loan']),
            $request->matched_lenders
        );
    }

    /**
     * Defect: the funding application's Lender Information could only show a
     * lender's name - the match had no legal name - so it could not show
     * "Lender Name - Legal Name" (R2 BUG-008).
     */
    #[Test]
    public function each_match_carries_the_lenders_legal_name(): void
    {
        $this->lenderIn('Term Loan', 'LendingClub', [], 'LendingClub Bank, N.A.');
        $this->lenderIn('Term Loan', 'OnDeck');

        $byName = collect($this->matcher->match($this->borrower, ['Term Loan']))->keyBy('name');

        $this->assertSame('LendingClub Bank, N.A.', $byName['LendingClub']['legal_name']);
        // A lender from before the legal name was required has none.
        $this->assertNull($byName['OnDeck']['legal_name']);
    }

    /** @return array<string, array{string, string}> loan application funding type => lender category */
    public static function loanApplicationFundingTypes(): array
    {
        return [
            'Line of Credit' => ['Line of Credit', 'Business Line of Credit'],
            'SBA Loan' => ['SBA Loan', 'SBA Financing'],
            'Refinance / Debt Consolidation' => ['Refinance / Debt Consolidation', 'Debt Consolidation / Refinance'],
        ];
    }

    /**
     * Defect: the loan application's funding types "Line of Credit", "SBA Loan"
     * and "Refinance / Debt Consolidation" are named differently from the
     * lender categories, and matching was by exact name, so they matched no
     * lender (SCRUM-434 S434-BUG-01).
     */
    #[Test]
    #[DataProvider('loanApplicationFundingTypes')]
    public function a_loan_application_funding_type_matches_the_lenders_of_its_lender_category(string $fundingType, string $category): void
    {
        $this->lenderIn($category, 'Bluevine Capital');

        [$match] = $this->matcher->match($this->borrower, [$fundingType]);

        $this->assertSame('Bluevine Capital', $match['name']);
        $this->assertSame($category, $match['product_category']);
    }

    #[Test]
    public function a_category_with_other_words_is_not_matched(): void
    {
        $this->lenderIn('Equipment Leasing', 'Leasing Co');
        $this->lenderIn('Business Credit Cards', 'Cards Co');
        $this->lenderIn('Equipment Financing', 'Balboa Capital');

        $this->assertSame(['Balboa Capital'], array_column($this->matcher->match($this->borrower, ['Equipment Financing', 'Unsecured Business Credit']), 'name'));
    }

    #[Test]
    public function a_category_name_is_matched_whatever_its_case(): void
    {
        $this->lenderIn('Term Loan', 'Fundbox');

        $this->assertSame(['Fundbox'], array_column($this->matcher->match($this->borrower, ['term loan']), 'name'));
    }

    /**
     * Defect: an Inactive lender was listed in Lender Information with its
     * match, and could be assigned (SCRUM-434 S434-BUG-02).
     */
    #[Test]
    public function an_inactive_lender_is_not_matched(): void
    {
        $inactive = $this->lenderIn('Term Loan', 'R2 Lender A');
        DB::table('lenders')->where('id', $inactive)->update(['status' => 'Inactive']);
        $this->lenderIn('Term Loan', 'Fundbox');

        $this->assertSame(['Fundbox'], array_column($this->matcher->match($this->borrower, ['Term Loan']), 'name'));
    }

    /** R2 BUG-008: each match carries "Lender Name - Legal Name", as the screens show it. */
    #[Test]
    public function each_match_carries_the_lenders_display_name(): void
    {
        $id = $this->lenderIn('Term Loan', 'Kabbage');
        DB::table('lenders')->where('id', $id)->update(['legal_name' => 'Kabbage Funding LLC']);

        [$match] = $this->matcher->match($this->borrower, ['Term Loan']);

        $this->assertSame(Lender::findOrFail($id)->displayName(), $match['display_name']);
        $this->assertSame('Kabbage - Kabbage Funding LLC', $match['display_name']);
    }

    private function lenderIn(string $category, string $name, array $requirements = [], ?string $legalName = null): int
    {
        $lenderId = DB::table('lenders')->insertGetId([
            'name' => $name,
            'legal_name' => $legalName,
            'contact_phone' => '(888) 555-0102',
            'status' => 'Active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->visibility($lenderId, $category, $requirements);

        return $lenderId;
    }

    private function visibility(int $lenderId, string $category, array $requirements = []): void
    {
        $categoryId = DB::table('lender_product_categories')->where('name', $category)->value('id')
            ?? DB::table('lender_product_categories')->insertGetId(['name' => $category, 'status' => 'Active', 'created_at' => now(), 'updated_at' => now()]);
        $sequenceId = DB::table('funding_sequencings')->insertGetId(['name' => "{$category} sequence", 'status' => 'Active', 'created_at' => now(), 'updated_at' => now()]);

        DB::table('lender_visibilities')->insert(array_merge([
            'lender_id' => $lenderId,
            'lender_product_category_id' => $categoryId,
            'funding_sequencing_id' => $sequenceId,
            'created_at' => now(),
            'updated_at' => now(),
        ], $requirements));
    }
}
