<?php

namespace Tests\Integration\OwnerSummary;

use App\Console\Commands\ListTelegramChats;
use App\Console\Commands\SendOwnerTelegramSummary;
use App\Models\Partner\Partner;
use App\Services\FCH\Common\OwnerSummary\OwnerTelegramSummaryService;
use App\Services\Telegram\TelegramClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/**
 * The short summary the scheduler sends to the owner's Telegram chat, one way:
 * last week on Sundays and last month on the 1st, both Eastern time.
 */
#[CoversClass(SendOwnerTelegramSummary::class)]
#[CoversClass(ListTelegramChats::class)]
#[CoversClass(OwnerTelegramSummaryService::class)]
#[CoversClass(TelegramClient::class)]
class TelegramSummaryCommandTest extends TestCase
{
    use InteractsWithPortalUsers;
    use RefreshDatabase;

    private const COMMAND = 'owner-summary:send-telegram-summary';
    private const TOKEN = '123456:secret-bot-token';
    private const CHAT = '987654321';
    private const SEND_URL = 'https://api.telegram.org/bot' . self::TOKEN . '/sendMessage';

    private Partner $host;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        $this->configure();
        Carbon::setTestNow(Carbon::parse('2026-11-08 10:00:00', 'America/New_York'));

        $this->host = $this->makeHostPartner();
        $this->makePartnerAdmin($this->host);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function configure(array $overrides = []): void
    {
        config(array_merge([
            'services.telegram.api_url' => 'https://api.telegram.org',
            'services.telegram.bot_token' => self::TOKEN,
            'services.telegram.owner_chat_id' => self::CHAT,
        ], $overrides));
    }

    private function telegramAccepts(): void
    {
        Http::fake([self::SEND_URL => Http::response(['ok' => true, 'result' => ['message_id' => 1]])]);
    }

    #[Test]
    public function the_week_goes_to_the_owners_chat_formatted_as_telegram_html(): void
    {
        $this->telegramAccepts();

        $this->artisan(self::COMMAND, ['period' => 'week'])->assertSuccessful();

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request) => $request->url() === self::SEND_URL
            && $request->method() === 'POST'
            && $request['chat_id'] === self::CHAT
            && $request['parse_mode'] === 'HTML'
            && str_starts_with($request['text'], '📊 <b>' . e($this->host->company_name) . " · Weekly Summary</b>\n🗓 1 – 7 Nov 2026\n")
            && str_contains($request['text'], "\n💰 <b>Net Earnings</b>\n")
            && str_contains($request['text'], "\n🎯 <b>Leads</b>\n"));
    }

    #[Test]
    public function the_month_covers_last_month(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-12-01 10:00:00', 'America/New_York'));
        $this->telegramAccepts();

        $this->artisan(self::COMMAND, ['period' => 'month'])->assertSuccessful();

        Http::assertSent(fn (Request $request) => str_starts_with($request['text'], '📊 <b>' . e($this->host->company_name) . " · Monthly Summary</b>\n🗓 November 2026\n"));
    }

    #[Test]
    public function nothing_is_sent_without_a_bot_token(): void
    {
        $this->configure(['services.telegram.bot_token' => null]);

        $this->artisan(self::COMMAND, ['period' => 'week'])
            ->expectsOutput(__('owner_summary.telegram.off'))
            ->assertSuccessful();

        Http::assertNothingSent();
    }

    #[Test]
    public function nothing_is_sent_without_a_chat(): void
    {
        $this->configure(['services.telegram.owner_chat_id' => '']);

        $this->artisan(self::COMMAND, ['period' => 'week'])
            ->expectsOutput(__('owner_summary.telegram.off'))
            ->assertSuccessful();

        Http::assertNothingSent();
    }

    #[Test]
    public function an_unknown_period_is_refused(): void
    {
        $this->artisan(self::COMMAND, ['period' => 'year'])
            ->expectsOutput(__('owner_summary.telegram.bad_period', ['periods' => 'week, month']))
            ->assertFailed();

        Http::assertNothingSent();
    }

    #[Test]
    public function a_refusal_from_telegram_fails_the_run_without_showing_the_token(): void
    {
        Http::fake([self::SEND_URL => Http::response(['ok' => false, 'error_code' => 400, 'description' => 'Bad Request: chat not found'], 400)]);

        $this->artisan(self::COMMAND, ['period' => 'week'])
            ->expectsOutput(__('telegram.refused', ['reason' => 'Bad Request: chat not found']))
            ->doesntExpectOutputToContain(self::TOKEN)
            ->assertFailed();
    }

    #[Test]
    public function an_unreachable_telegram_fails_the_run_without_showing_the_token(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 28: timed out for ' . self::SEND_URL));

        $this->artisan(self::COMMAND, ['period' => 'week'])
            ->expectsOutput(__('telegram.unreachable'))
            ->doesntExpectOutputToContain(self::TOKEN)
            ->assertFailed();
    }

    #[Test]
    public function the_chat_id_helper_lists_each_chat_that_has_messaged_the_bot_once(): void
    {
        $chat = ['id' => 987654321, 'type' => 'private', 'first_name' => 'Fch', 'last_name' => 'Owner'];
        Http::fake(['https://api.telegram.org/bot' . self::TOKEN . '/getUpdates' => Http::response(['ok' => true, 'result' => [
            ['update_id' => 1, 'message' => ['chat' => $chat]],
            ['update_id' => 2, 'message' => ['chat' => $chat]],
        ]])]);

        $this->artisan('telegram:chat-ids')
            ->expectsTable(['Chat id', 'Name', 'Type'], [['987654321', 'Fch Owner', 'private']])
            ->assertSuccessful();
    }
}
