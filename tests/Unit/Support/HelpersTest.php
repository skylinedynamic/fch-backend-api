<?php

namespace Tests\Unit\Support;

use Carbon\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The global helpers in app/Helpers/helpers.php - how every name, amount,
 * phone number, date and link on the platform is written out.
 */
class HelpersTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // ── Names ──────────────────────────────────────────────────────────

    public static function names(): array
    {
        return [
            'all three parts' => [['Ann', 'B', 'Cole'], 'Ann B Cole'],
            'no middle name' => [['Ann', null, 'Cole'], 'Ann Cole'],
            'blank and padded parts' => [['  Ann ', '  ', ' Cole'], 'Ann Cole'],
            'first name only' => [['Ann'], 'Ann'],
            'nothing at all' => [[null, null, null], ''],
        ];
    }

    #[Test]
    #[DataProvider('names')]
    public function a_name_is_built_from_the_parts_that_are_there(array $parts, string $expected): void
    {
        $this->assertSame($expected, personName(...$parts));
    }

    // ── Amounts ────────────────────────────────────────────────────────

    #[Test]
    public function amounts_are_written_with_separators_and_a_symbol(): void
    {
        $this->assertSame('1,234,567.89', formatComma(1234567.891));
        $this->assertSame('1,235', formatComma('1234.5', 0));
        $this->assertNull(formatComma(null));
        $this->assertNull(formatComma(''));

        $this->assertSame('$1,234.50', currencyFormat(1234.5));
        $this->assertSame('$0.00', currencyFormat(0));
        $this->assertSame('€1.234', currencyFormat(1234, 0, '€', 1, '.'));
        $this->assertSame('$2,469.00', currencyFormat(1234.5, 2, '$', 2));
        $this->assertNull(currencyFormat(null));
        $this->assertNull(currencyFormat(''));

        // Defect (O38): the sign went after the symbol - "$-50.00" for a refund.
        $this->assertSame('-$50.00', currencyFormat(-50));
        $this->assertSame('-$1,234.50', currencyFormat(-1234.5));
        $this->assertSame('-$100.00', currencyFormat(-50, 2, '$', 2));
    }

    /**
     * Defect (O50): without CRON_ERROR_NOTIFY_EMAILS every scheduler failure
     * alert from any environment went to two named personal addresses.
     */
    public function test_cron_alerts_have_no_built_in_recipients(): void
    {
        $config = file_get_contents(config_path('app.php'));

        $this->assertDoesNotMatchRegularExpression("/'cron_notify_emails'\s*=>\s*env\('CRON_ERROR_NOTIFY_EMAILS',\s*'[^']+'\)/", $config);
        $this->assertStringNotContainsString('@gmail.com', $config);
    }

    public static function ranges(): array
    {
        return [
            'stored form' => ['5000-250000', '$5,000 - $250,000'],
            'already formatted' => ['$5,000 - $250,000', '$5,000 - $250,000'],
            'a single amount' => ['75000', '$75,000'],
            'words are left as they are' => ['Varies - Call', 'Varies - Call'],
            'free text without a dash' => ['Up to you', 'Up to you'],
        ];
    }

    #[Test]
    #[DataProvider('ranges')]
    public function an_amount_range_is_written_as_currency(string $range, string $expected): void
    {
        $this->assertSame($expected, formatAmountRange($range));
    }

    #[Test]
    public function an_empty_range_is_null(): void
    {
        $this->assertNull(formatAmountRange(null));
        $this->assertNull(formatAmountRange(''));
    }

    // ── Dates ──────────────────────────────────────────────────────────

    public static function dates(): array
    {
        return [
            'US month-day-year with dashes' => ['03-07-2026', '2026-03-07'],
            'US month/day/year with slashes' => ['3/7/2026', '2026-03-07'],
            'ISO' => ['2026-03-07', '2026-03-07'],
            'a timestamp' => ['2026-03-07 15:45:00', '2026-03-07'],
            'words' => ['March 7, 2026', '2026-03-07'],
        ];
    }

    #[Test]
    #[DataProvider('dates')]
    public function a_date_is_normalised_to_iso(string $input, string $expected): void
    {
        $this->assertSame($expected, normalizeDate($input));
    }

    /**
     * Defect: a day-first date such as 13/01/2026 matched the month-first
     * pattern and came back as the impossible "2026-13-01", which then failed
     * (or was silently mangled) wherever it was stored or compared.
     */
    #[Test]
    public function an_impossible_date_is_null(): void
    {
        $this->assertNull(normalizeDate('13/01/2026'));
        $this->assertNull(normalizeDate('02-30-2026'));
        $this->assertNull(normalizeDate('not a date'));
        $this->assertNull(normalizeDate(null));
        $this->assertNull(normalizeDate(''));
    }

    #[Test]
    public function a_calendar_date_is_never_shifted_into_the_viewers_zone(): void
    {
        $this->assertSame('2026-08-31', toDateOnly('2026-08-31'));
        $this->assertSame('08/31/2026', toDateOnly('2026-08-31', 'm/d/Y'));
        $this->assertNull(toDateOnly(null));
        $this->assertNull(toDateOnly('not a date'));
    }

    #[Test]
    public function an_instant_is_shown_in_the_requested_zone(): void
    {
        $this->assertSame('2026-08-30 20:00', toTimezone('2026-08-31 00:00:00', 'America/New_York', 'Y-m-d H:i'));
        $this->assertSame('2026-08-31 05:45', toTimezone('2026-08-31 00:00:00', 'Asia/Kathmandu', 'Y-m-d H:i'));
        $this->assertNull(toTimezone(null, 'UTC'));
    }

    /**
     * BUG-120: a date-only column (funded date, payout date) is the day it was
     * in the caller's zone - 03:30 on 21 Oct in Dhaka is 20 Oct 21:30 UTC.
     */
    #[Test]
    public function today_is_the_day_in_the_callers_timezone_header(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-20 21:30:00', 'UTC'));

        $this->assertSame('2026-10-20', todayInRequestTimezone());

        $this->app['request']->headers->set('x-timezone', 'Asia/Dhaka');
        $this->assertSame('2026-10-21', todayInRequestTimezone());

        $this->app['request']->headers->set('x-timezone', 'Not/AZone');
        $this->assertSame('2026-10-20', todayInRequestTimezone());
    }

    #[Test]
    public function an_instant_defaults_to_the_callers_timezone_header(): void
    {
        $this->app['request']->headers->set('x-timezone', 'America/Los_Angeles');

        $this->assertSame('2026-08-30 17:00', toTimezone('2026-08-31 00:00:00', null, 'Y-m-d H:i'));
    }

    public static function timesInBusiness(): array
    {
        return [
            'years, months and days' => ['2023-01-05', '3 Years 2 Months 5 Days'],
            'exactly one of each' => ['2025-02-09', '1 Year 1 Month 1 Day'],
            'whole years' => ['2024-03-10', '2 Years'],
            'started today' => ['2026-03-10', '0 Days'],
            'starts in the future' => ['2026-04-01', '0 Days'],
        ];
    }

    /**
     * Includes a defect: a business started today read "0 Day" - singular
     * for zero, and unlike the future-start case's "0 Days".
     */
    #[Test]
    #[DataProvider('timesInBusiness')]
    public function time_in_business_is_written_in_years_months_and_days(string $start, string $expected): void
    {
        Carbon::setTestNow('2026-03-10 12:00:00');

        $this->assertSame($expected, calculateTimeInBusiness($start));
    }

    #[Test]
    public function no_start_date_reads_not_available(): void
    {
        $this->assertSame('N/A', calculateTimeInBusiness(null));
    }

    // ── Phone numbers ──────────────────────────────────────────────────

    public static function phones(): array
    {
        return [
            'brackets and dash' => ['(555) 234-5678', '+15552345678'],
            'dots' => ['555.234.5678', '+15552345678'],
            'with the country code' => ['1-555-234-5678', '+15552345678'],
            'already E.164' => ['+15552345678', '+15552345678'],
        ];
    }

    #[Test]
    #[DataProvider('phones')]
    public function a_us_phone_is_stored_in_e164(string $raw, string $expected): void
    {
        $this->assertSame($expected, normalizePhoneE164($raw));
    }

    #[Test]
    public function a_number_that_is_not_a_us_number_is_not_normalised(): void
    {
        $this->assertNull(normalizePhoneE164('555-1234'));
        $this->assertNull(normalizePhoneE164('(055) 234-5678'), 'area code may not start with 0');
        $this->assertNull(normalizePhoneE164('(555) 134-5678'), 'exchange may not start with 1');
        $this->assertNull(normalizePhoneE164(''));
        $this->assertNull(normalizePhoneE164(null));
    }

    #[Test]
    public function a_foreign_number_drops_its_trunk_zero(): void
    {
        $this->assertSame('+447911123456', normalizePhoneE164('07911 123456', '+44'));
        $this->assertSame('+447911123456', normalizePhoneE164('+44 7911 123456', '44'));
    }

    #[Test]
    public function a_phone_is_displayed_the_us_way_or_left_alone(): void
    {
        $this->assertSame('(555) 234-5678', formatPhoneDisplay('+15552345678'));
        $this->assertSame('(555) 234-5678', formatPhoneDisplay('5552345678'));
        $this->assertSame('+44 7911 123456', formatPhoneDisplay('+44 7911 123456'));
        $this->assertNull(formatPhoneDisplay(null));
    }

    // ── Links ──────────────────────────────────────────────────────────

    #[Test]
    public function a_link_keeps_its_scheme_or_gets_the_right_one(): void
    {
        $this->assertSame('https://portal.example.org/login', concatUrl('portal.example.org', '/login'));
        $this->assertSame('http://portal.example.org/login', concatUrl('http://portal.example.org/', 'login'));
        $this->assertSame('https://portal.example.org/a', concatUrl('https://portal.example.org', 'a'));
        $this->assertSame('http://portal.test/a', concatUrl('portal.test', 'a'), 'a .test domain is local');
        $this->assertSame('http://localhost:3000/a', concatUrl('localhost:3000', 'a'));
        $this->assertSame('https://fallback.example.org/a', concatUrl(null, 'a', 'fallback.example.org'));
        $this->assertSame('', concatUrl(null, 'a'));
    }

    #[Test]
    public function make_url_joins_a_domain_and_a_path(): void
    {
        $this->assertSame('https://example.org/a/b', make_url(' example.org/ ', '/a/b'));
        $this->assertSame('http://example.org', make_url('http://example.org/'));
    }

    #[Test]
    public function a_url_is_made_safe_to_put_in_a_link(): void
    {
        $this->assertSame('https://x.org/My%20File.pdf', encodeUrl('https://x.org/My File.pdf'));
        $this->assertSame('https://x.org/a%E2%80%93b.pdf', encodeUrl('https://x.org/a–b.pdf'));
        $this->assertSame('https://x.org/a?b=1&c=2', encodeUrl('https://x.org/a?b=1&c=2'));
    }
}
