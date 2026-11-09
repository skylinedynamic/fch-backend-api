<?php

namespace Tests\Unit\Services\Borrower;

use App\Services\FCH\Common\Borrower\IsoftpullParserService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The FICO score read from a credit report's text.
 *
 * Defect (BUG-074 side note): QA's report said FICO 712, but the form was filled
 * with 850. The last-resort pattern took the first three-digit number ending
 * any line after the word "FICO", across lines, so "FICO Score 712 (Good)"
 * followed by "Score range: 300 - 850" gave 850. A score is now only read from
 * its own line, never from a range, and only from 300 to 850.
 */
#[CoversClass(IsoftpullParserService::class)]
class IsoftpullParserServiceTest extends TestCase
{
    private function fico(string $text): ?int
    {
        return (new IsoftpullParserService())->parse($text)['metrics']['fico_score'];
    }

    #[Test]
    public function the_isoftpull_score_line_is_read(): void
    {
        $this->assertSame(697, $this->fico("Score Model: FICO 8\tScore: 697\nCredit Limit: $5000\n"));
    }

    /** @return array<string, array{string, int}> */
    public static function scoreLines(): array
    {
        return [
            'label with colon' => ["FICO Score: 712\nScore range: 300 - 850\n", 712],
            'no colon, rating after' => ["FICO Score 712 (Good)\nScore range: 300 - 850\nTrade Accounts\n", 712],
            'model number' => ["FICO Score 8: 712\nScores range from 300 to 850\n", 712],
            'registered mark' => ["FICO\u{00AE} Score 8 712 Good\nRange 300-850\n", 712],
        ];
    }

    #[Test]
    #[DataProvider('scoreLines')]
    public function the_score_on_the_fico_line_is_read_not_a_number_from_a_later_line(string $text, int $expected): void
    {
        $this->assertSame($expected, $this->fico($text));
    }

    /** @return array<string, array{string}> */
    public static function noScore(): array
    {
        return [
            'only the range' => ["FICO scores range from 300 to 850\nSomething else\n"],
            'range with a dash' => ["FICO Score range 300 - 850\nNext line\n"],
            'number on a later line' => ["About FICO\nAccount 123\nTotal 712\n"],
            'out of range' => ["FICO Score: 9999\n"],
            'below range' => ["FICO Score: 120\n"],
        ];
    }

    #[Test]
    #[DataProvider('noScore')]
    public function no_score_is_invented(string $text): void
    {
        $this->assertNull($this->fico($text));
    }

    /**
     * A real iSoftPull report's text with every name, number and value
     * replaced (only its labels and layout are left): what the parser must keep
     * reading. Checked against five real reports on 2026-11-03.
     */
    private function anonymisedReport(): string
    {
        return file_get_contents(base_path('tests/Fixtures/CreditReports/isoftpull-report-anonymised.txt'));
    }

    #[Test]
    public function a_real_isoftpull_report_layout_is_read_in_full(): void
    {
        $parsed = (new IsoftpullParserService())->parse($this->anonymisedReport());

        $this->assertSame(712, $parsed['metrics']['fico_score']);
        $this->assertCount(4, $parsed['trade_accounts']);
        $this->assertSame(['OPEN', 'OPEN', 'OPEN', 'OPEN'], array_column($parsed['trade_accounts'], 'status'));
        $this->assertSame([5.0, 5.0, 5555.0, 555.0], array_column($parsed['trade_accounts'], 'balance'));
        // "Past Due: N/A" on every account: unknown, not zero and not delinquent.
        $this->assertSame([null, null, null, null], array_column($parsed['trade_accounts'], 'past_due'));
        $this->assertSame(0, $parsed['metrics']['delinquent_accounts']);
        $this->assertCount(3, $parsed['addresses']);
        $this->assertSame([], $parsed['inquiries']);
    }

    /**
     * Trade lines are read from the report's "Trade Accounts" section. A
     * report without that heading (QA's synthetic report on BUG-074: "0 trade
     * accounts") gives none rather than a guess - inquiries use the same
     * "Company:" blocks, so taking every block would count them as accounts.
     */
    #[Test]
    public function trade_lines_come_from_the_trade_accounts_section_only(): void
    {
        $withoutHeading = str_replace("Trade Accounts\n", '', $this->anonymisedReport());

        $this->assertSame([], (new IsoftpullParserService())->parse($withoutHeading)['trade_accounts']);
    }

    /** The commission invoice QA could upload as a credit report: nothing in it is credit data. */
    #[Test]
    public function an_invoice_gives_no_score_trades_or_inquiries(): void
    {
        $parsed = (new IsoftpullParserService())->parse(implode("\n", [
            'Invoice No: #INV-00000000001', 'ADDRESS', 'Four Corner Funding', '100 Main St', 'Tampa, Florida 33601',
            'Phone: (555) 555-0100', 'PARTNER DETAILS', 'Pat Partner', 'TOTAL AMOUNT', '$1,250.00',
            'Payee Name: Pat Partner', 'Total Amount: $1,250.00', 'NOTES: Cheque Payment',
        ]));

        $this->assertNull($parsed['metrics']['fico_score']);
        $this->assertSame([], $parsed['trade_accounts']);
        $this->assertSame([], $parsed['inquiries']);
    }
}
