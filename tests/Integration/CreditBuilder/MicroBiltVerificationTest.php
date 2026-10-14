<?php

namespace Tests\Integration\CreditBuilder;

use App\Models\Borrower\Borrower;
use App\Models\Common\AppConfiguration;
use App\Models\CreditBuilder\BorrowerTaskVerificationRequest;
use App\Models\CreditBuilder\Tier;
use App\Models\CreditBuilder\TierRelatedApi;
use App\Models\CreditBuilder\TierTask;
use App\Models\Partner\Partner;
use App\Services\FCH\Common\CreditBuilder\VerificationCenterService;
use App\Services\Global\MicroBilt\MicroBiltService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/**
 * The host approving a client's Tier 0 credit-builder task "by API": the
 * business is looked up at MicroBilt and the task is verified only when
 * MicroBilt finds the record. MicroBilt is faked.
 */
#[CoversClass(VerificationCenterService::class)]
#[CoversClass(MicroBiltService::class)]
class MicroBiltVerificationTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithPortalUsers;

    private const BASE = 'https://microbilt.example/';
    private const SECRET = 'mb-client-secret-7f3a91';

    private Partner $partner;
    private Tier $tierZero;
    private Tier $tierOne;
    private TierTask $einTask;
    /** @var array<int, MessageLogged> */
    private array $logged = [];

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        config(['logging.default' => 'null']);
        Event::listen(MessageLogged::class, fn (MessageLogged $message) => $this->logged[] = $message);

        $host = $this->makeHostPartner();
        $this->partner = $this->makeWhiteLabelPartner();
        $this->actingAsUser($this->makePartnerAdmin($host));

        AppConfiguration::set('microbilt_mode', 'sandbox', 'microbilt');
        AppConfiguration::set('microbilt_base_url_sandbox', self::BASE, 'microbilt');
        AppConfiguration::set('microbilt_client_id_sandbox', 'mb-client-id', 'microbilt');
        AppConfiguration::set('microbilt_client_secret_sandbox', self::SECRET, 'microbilt');

        $this->tierZero = Tier::create(['name' => 'Tier 0', 'title' => 'Foundation', 'code' => 'T0', 'order' => 0]);
        $this->tierOne = Tier::create(['name' => 'Tier 1', 'title' => 'Starter', 'code' => 'T1', 'order' => 1]);
        $api = TierRelatedApi::create(['name' => 'EIN Verification Api', 'provider' => TierRelatedApi::PROVIDER_MICROBILT, 'tier_id' => $this->tierZero->id]);
        $this->einTask = TierTask::create(['tier_id' => $this->tierZero->id, 'title' => 'Verify your EIN', 'related_api_id' => $api->id, 'order' => 1]);
    }

    private function borrower(string $business, string $ein): Borrower
    {
        return $this->makeBorrower($this->partner, ['business_name' => $business, 'business_ein' => $ein, 'current_tier' => $this->tierZero->id]);
    }

    private function pendingRequest(Borrower $borrower): BorrowerTaskVerificationRequest
    {
        return BorrowerTaskVerificationRequest::forceCreate([
            'borrower_id' => $borrower->id,
            'partner_id' => $this->partner->id,
            'task_id' => $this->einTask->id,
            'tier_id' => $this->tierZero->id,
            'status' => BorrowerTaskVerificationRequest::PENDING,
        ]);
    }

    /** MicroBilt answering every report with this status code (0 = found). */
    private function fakeMicroBilt(int $statusCode = 0, int $httpStatus = 200): void
    {
        Http::fake([
            self::BASE . 'OAuth/Token' => Http::response(['access_token' => 'mb-token', 'expires_in' => 3600]),
            self::BASE . 'MBBusinessReport/*' => $httpStatus === 200
                ? Http::response(['MsgRsHdr' => ['Status' => ['StatusCode' => $statusCode, 'StatusDesc' => $statusCode === 0 ? 'OK' : 'No Match']]])
                : Http::response('Service Unavailable', $httpStatus),
        ]);
    }

    private function verifyByApi(BorrowerTaskVerificationRequest ...$requests): array
    {
        return app(VerificationCenterService::class)->updateStatus([
            'request_ids' => array_map(fn ($request) => $request->id, $requests),
            'status' => BorrowerTaskVerificationRequest::COMPLETED,
            'is_api' => true,
        ]);
    }

    #[Test]
    public function a_business_microbilt_finds_is_verified_and_moves_up_a_tier(): void
    {
        $this->fakeMicroBilt();
        $borrower = $this->borrower('Whitfield Bakery LLC', '12-3456789');
        $request = $this->pendingRequest($borrower);

        $results = $this->verifyByApi($request);

        $this->assertTrue($results[0]['is_verified']);
        $request->refresh();
        $this->assertSame(BorrowerTaskVerificationRequest::COMPLETED, $request->status);
        $this->assertSame(BorrowerTaskVerificationRequest::MICROBILT_VERIFIED, $request->verified_with);
        $this->assertSame($this->tierOne->id, (int) $borrower->fresh()->current_tier);
        Http::assertSent(fn (Request $sent) => str_ends_with($sent->url(), 'MBBusinessReport/GetReport')
            && $sent['FEIN'] === '123456789'
            && $sent['CorporateDetails']['CompanyName'] === 'Whitfield Bakery LLC');
    }

    #[Test]
    public function a_business_microbilt_does_not_find_is_rejected(): void
    {
        $this->fakeMicroBilt(statusCode: 2);
        $request = $this->pendingRequest($this->borrower('Ghost Holdings', '98-7654321'));

        $results = $this->verifyByApi($request);

        $this->assertFalse($results[0]['is_verified']);
        $this->assertSame(BorrowerTaskVerificationRequest::REJECTED, $request->fresh()->status);
        $this->assertSame(__('verification_center.no_matching_business_record'), $request->fresh()->note);
    }

    /**
     * Defect: any exception from the MicroBilt call - an outage, a timeout,
     * bad credentials - was treated as "no matching business record": the
     * task was rejected (and a rejected task can never be verified again)
     * and the borrower was emailed the rejection.
     */
    #[Test]
    public function a_microbilt_outage_leaves_the_task_pending(): void
    {
        $this->fakeMicroBilt(httpStatus: 503);
        $request = $this->pendingRequest($this->borrower('Whitfield Bakery LLC', '12-3456789'));

        $results = $this->verifyByApi($request);

        $this->assertSame(BorrowerTaskVerificationRequest::PENDING, $request->fresh()->status);
        $this->assertFalse($results[0]['is_verified']);
        $this->assertTrue($results[0]['skipped'] ?? false);
        $this->assertSame(__('verification_center.verification_service_unavailable'), $results[0]['message']);
    }

    /**
     * Defect: the borrower was read from the first request only, so verifying
     * two clients' tasks in one call looked the second client's business up
     * with the first client's name and EIN.
     */
    #[Test]
    public function each_request_is_checked_against_its_own_borrower(): void
    {
        $this->fakeMicroBilt();
        $first = $this->pendingRequest($this->borrower('Whitfield Bakery LLC', '12-3456789'));
        $second = $this->pendingRequest($this->borrower('Harbor Freight Co', '45-6789012'));

        $this->verifyByApi($first, $second);

        Http::assertSent(fn (Request $sent) => str_ends_with($sent->url(), 'GetReport') && $sent['FEIN'] === '123456789');
        Http::assertSent(fn (Request $sent) => str_ends_with($sent->url(), 'GetReport') && $sent['FEIN'] === '456789012'
            && $sent['CorporateDetails']['CompanyName'] === 'Harbor Freight Co');
    }

    /**
     * Defect: every authentication wrote the MicroBilt client secret, in
     * plain text, to the application log.
     */
    #[Test]
    public function the_client_secret_is_never_logged(): void
    {
        $this->fakeMicroBilt();

        $this->verifyByApi($this->pendingRequest($this->borrower('Whitfield Bakery LLC', '12-3456789')));

        $this->assertNotEmpty($this->logged);
        foreach ($this->logged as $message) {
            $this->assertStringNotContainsString(self::SECRET, $message->message . json_encode($message->context));
        }
    }

    /**
     * Defect (O55): token caching was commented out "for debugging", so every
     * verification authenticated with MicroBilt again.
     */
    #[Test]
    public function the_token_is_reused_until_it_expires(): void
    {
        $this->fakeMicroBilt();
        $first = $this->pendingRequest($this->borrower('Whitfield Bakery LLC', '12-3456789'));
        $second = $this->pendingRequest($this->borrower('Harbor Freight Co', '98-7654321'));

        $this->verifyByApi($first);
        $this->verifyByApi($second);

        $this->assertCount(1, Http::recorded(fn (Request $sent) => str_ends_with($sent->url(), 'OAuth/Token')));
    }

    /** Defect (O55): every full MicroBilt response - a business report - was logged at info. */
    #[Test]
    public function the_report_itself_is_not_written_to_the_log_at_info(): void
    {
        $this->fakeMicroBilt();

        $this->verifyByApi($this->pendingRequest($this->borrower('Whitfield Bakery LLC', '12-3456789')));

        foreach ($this->logged as $message) {
            if ($message->level === 'info') {
                $this->assertArrayNotHasKey('response', $message->context, $message->message);
            }
        }
    }
}
