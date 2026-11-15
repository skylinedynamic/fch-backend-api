<?php

namespace Tests\Unit\OwnerSummary;

use App\Services\FCH\Common\OwnerSummary\OwnerTelegramMessage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** The summary sent to Telegram: the screen's six headline cards, in Telegram HTML. */
#[CoversClass(OwnerTelegramMessage::class)]
class OwnerTelegramMessageTest extends TestCase
{
    private function summary(array $changes = []): array
    {
        return array_replace_recursive(require base_path('tests/Fixtures/owner_weekly_report.php'), $changes);
    }

    #[Test]
    public function a_week_reads_like_the_six_headline_cards(): void
    {
        $this->assertSame(implode("\n", [
            '📊 <b>Four Corner · Weekly Summary</b>',
            '🗓 1 – 7 Nov 2026',
            '',
            '💰 <b>Net Earnings</b>',
            '<b>$14.3K</b>   📈 +30%   <i>was $11.0K</i>',
            '',
            '🏦 <b>Funded Volume</b>',
            '<b>$2.31M</b>   📉 -22%   <i>was $2.96M</i>',
            '├ FCH: $1.31M',
            '└ Partners: $1.00M',
            '',
            '✅ <b>Funded Deals</b>',
            '<b>6</b>   📉 -25%   <i>was 8</i>',
            '├ FCH: 4',
            '└ Partners: 2',
            '',
            '🤝 <b>Partners</b>',
            '<b>26</b>   22 active · 3 new',
            '',
            '👥 <b>Clients</b>',
            '<b>410</b>   380 active · 21 new',
            '├ FCH: 150',
            '└ Partners: 260',
            '',
            '🎯 <b>Leads</b>',
            '<b>41</b>   🆕 new',
            '├ FCH: 15',
            '└ Partners: 26',
        ]), OwnerTelegramMessage::make($this->summary(), 'Four Corner'));
    }

    #[Test]
    public function a_month_is_titled_by_its_name(): void
    {
        $message = OwnerTelegramMessage::make($this->summary(['period' => [
            'key' => 'last_month',
            'from' => '2026-10-01',
            'to' => '2026-10-31',
        ]]), 'Four Corner');

        $this->assertStringStartsWith("📊 <b>Four Corner · Monthly Summary</b>\n🗓 October 2026\n\n", $message);
    }

    #[Test]
    public function nothing_before_to_compare_with_says_new_or_no_change_without_a_was(): void
    {
        $message = OwnerTelegramMessage::make($this->summary(['kpis' => [
            'cash_collected' => ['current' => 0, 'previous' => 0],
            'funded' => ['amount' => 5000, 'deals' => 1, 'previous_amount' => 0, 'previous_deals' => 0],
        ]]), 'Four Corner');

        $this->assertStringContainsString("<b>\$0</b>   ➖ no change\n", $message);
        $this->assertStringContainsString("<b>\$5.0K</b>   🆕 new\n", $message);
        $this->assertStringContainsString("<b>1</b>   🆕 new\n", $message);
    }

    #[Test]
    public function an_unchanged_number_shows_a_flat_trend(): void
    {
        $message = OwnerTelegramMessage::make($this->summary(['kpis' => [
            'funded' => ['deals' => 2, 'previous_deals' => 2],
        ]]), 'Four Corner');

        $this->assertStringContainsString("<b>2</b>   ➖ 0%   <i>was 2</i>\n", $message);
    }

    #[Test]
    public function the_company_name_is_escaped_so_telegram_reads_it_as_text(): void
    {
        $message = OwnerTelegramMessage::make($this->summary(), 'R&D <Capital>');

        $this->assertStringStartsWith('📊 <b>R&amp;D &lt;Capital&gt; · Weekly Summary</b>', $message);
    }
}
