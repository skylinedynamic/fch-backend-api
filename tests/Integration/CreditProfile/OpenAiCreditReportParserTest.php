<?php

namespace Tests\Integration\CreditProfile;

use App\Services\FCH\Common\Borrower\OpenAiParserService;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Reading an uploaded credit report with OpenAI (the "openai" parser mode):
 * the report text goes to the chat completions API and the JSON that comes
 * back is normalised into the shape the review screen expects. Every failure
 * returns that same shape, with the reason in the summary, rather than
 * throwing. OpenAI is faked.
 */
#[CoversClass(OpenAiParserService::class)]
class OpenAiCreditReportParserTest extends TestCase
{
    private const REPORT = "EXPERIAN CREDIT REPORT\nDANA WHITFIELD  SSN XXX-XX-6789\nFICO 8: 742\nCHASE CARD  BAL 1,200  LIMIT 10,000";

    private array $logged = [];

    protected function setUp(): void
    {
        parent::setUp();

        config(['logging.default' => 'null']);
        Event::listen(MessageLogged::class, fn (MessageLogged $message) => $this->logged[] = $message);
    }

    private function reply(string $content, int $status = 200): void
    {
        Http::fake(['api.openai.com/v1/chat/completions' => $status === 200
            ? Http::response(['choices' => [['message' => ['content' => $content]]]])
            : Http::response(['error' => ['message' => 'Incorrect API key provided']], $status)]);
    }

    private function extraction(): array
    {
        return [
            'personal_info' => ['name' => 'Dana Whitfield', 'dob' => '1985-04-02', 'ssn' => 'XXX-XX-6789', 'email' => null, 'phone' => '5550142', 'nickname' => 'ignored'],
            'metrics' => ['fico_score' => 742, 'recent_inquiries' => 2, 'open_accounts_analysis' => ['balance' => 1200, 'credit_limit' => 10000], 'made_up' => 'ignored'],
            'addresses' => [['type' => 'current', 'street' => '1 Main St', 'city' => 'Austin', 'state' => 'TX', 'zip' => '73301']],
            'trade_accounts' => [['company' => 'CHASE CARD', 'status' => 'open', 'balance' => '1200', 'limit' => '10000', 'past_due' => 'n/a', 'months_reviewed' => '24']],
            'inquiries' => [['date' => '2026-05-01', 'company' => 'AMEX']],
        ];
    }

    private function parse(string $model = 'gpt-4o-mini'): array
    {
        return (new OpenAiParserService('sk-test-key', $model))->parse(self::REPORT);
    }

    #[Test]
    public function the_report_is_sent_and_the_reply_normalised(): void
    {
        $this->reply(json_encode($this->extraction()));

        $result = $this->parse();

        Http::assertSent(fn (Request $request) => $request->hasHeader('Authorization', 'Bearer sk-test-key')
            && $request['model'] === 'gpt-4o-mini'
            && $request['response_format'] === ['type' => 'json_object']
            && str_contains($request['messages'][1]['content'], self::REPORT));
        $this->assertSame(['name' => 'Dana Whitfield', 'dob' => '1985-04-02', 'ssn' => 'XXX-XX-6789', 'email' => null, 'phone' => '5550142'], $result['personal_info']);
        $this->assertSame(742, $result['metrics']['fico_score']);
        $this->assertArrayNotHasKey('made_up', $result['metrics']);
        $this->assertSame(1200, $result['metrics']['open_accounts_analysis']['balance']);
        $this->assertNull($result['metrics']['open_accounts_analysis']['utilization_ratio'], 'missing keys keep the empty shape');
        $this->assertSame('CURRENT', $result['addresses'][0]['type']);
        $trade = $result['trade_accounts'][0];
        $this->assertSame(['OPEN', 1200.0, 10000.0, null, 24], [$trade['status'], $trade['balance'], $trade['limit'], $trade['past_due'], $trade['months_reviewed']]);
        $this->assertSame(['date' => '2026-05-01', 'company' => 'AMEX', 'industry' => ''], $result['inquiries'][0]);
        $this->assertNotSame('Parsed from iSoftPull credit report.', $result['metrics']['summary'], 'the summary is generated from the data');
    }

    #[Test]
    public function a_reply_wrapped_in_a_code_fence_or_prose_still_parses(): void
    {
        $this->reply("```json\n" . json_encode($this->extraction()) . "\n```");
        $this->assertSame(742, $this->parse()['metrics']['fico_score']);

        Http::swap(new \Illuminate\Http\Client\Factory());
        $this->reply('Here is the data: ' . json_encode($this->extraction()) . ' Hope this helps.');
        $this->assertSame(742, $this->parse()['metrics']['fico_score']);
    }

    #[Test]
    public function models_without_json_mode_are_not_sent_it(): void
    {
        $this->reply(json_encode($this->extraction()));

        $this->parse('o3-mini');

        Http::assertSent(fn (Request $request) => !isset($request['response_format']));
    }

    /**
     * Defect: a reply that was not JSON set the summary to "Failed to parse
     * OpenAI response as JSON...", and parse() then overwrote every summary
     * with one generated from the (empty) data - so the reviewer saw an
     * ordinary-looking summary over a blank extraction and no reason why.
     */
    #[Test]
    public function each_failure_returns_the_empty_shape_with_the_reason(): void
    {
        $this->reply('', 401);
        $result = $this->parse();
        $this->assertSame([], $result['trade_accounts']);
        $this->assertNull($result['metrics']['fico_score']);
        $this->assertStringContainsString('OpenAI API error (401)', $result['metrics']['summary']);

        Http::swap(new \Illuminate\Http\Client\Factory());
        $this->reply('');
        $this->assertStringContainsString('empty response', $this->parse()['metrics']['summary']);

        Http::swap(new \Illuminate\Http\Client\Factory());
        $this->reply('I could not read this report.');
        $this->assertStringContainsString('Failed to parse OpenAI response as JSON', $this->parse()['metrics']['summary']);
    }

    #[Test]
    public function a_timeout_returns_the_empty_shape(): void
    {
        Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('cURL error 28: Operation timed out'));

        $this->assertStringContainsString('timed out', $this->parse()['metrics']['summary']);
    }

    #[Test]
    public function neither_the_report_nor_the_extraction_is_logged(): void
    {
        $this->reply(json_encode($this->extraction()));

        $this->parse();

        // A successful extraction is not logged at all - not even its size.
        $this->assertSame([], array_filter($this->logged, fn ($m) => $m->level === 'info'));
        foreach ($this->logged as $message) {
            $line = $message->message . json_encode($message->context);
            $this->assertStringNotContainsString('WHITFIELD', strtoupper($line));
            $this->assertStringNotContainsString('6789', $line);
            $this->assertStringNotContainsString('sk-test-key', $line);
        }
    }
}
