<?php

namespace Tests\Unit\Services\Global\MicroBilt;

use App\Models\Common\AppConfiguration;
use App\Services\Global\MicroBilt\MicroBiltService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class MicroBiltServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        AppConfiguration::set('microbilt_mode', 'sandbox');
        AppConfiguration::set('microbilt_base_url_sandbox', 'https://microbilt.test/');
        AppConfiguration::set('microbilt_client_id_sandbox', 'test-client-id');
        AppConfiguration::set('microbilt_client_secret_sandbox', 'super-secret-value');
    }

    public function test_authenticate_posts_client_credentials_and_returns_the_access_token(): void
    {
        Http::fake([
            'https://microbilt.test/OAuth/Token' => Http::response([
                'access_token' => 'abc123',
                'expires_in' => 3600,
            ], 200),
        ]);

        $token = (new MicroBiltService)->authenticate();

        $this->assertSame('abc123', $token);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://microbilt.test/OAuth/Token'
                && $request['grant_type'] === 'client_credentials'
                && $request['client_id'] === 'test-client-id'
                && $request['client_secret'] === 'super-secret-value';
        });
    }

    public function test_authenticate_throws_when_the_response_is_not_successful(): void
    {
        Http::fake([
            'https://microbilt.test/OAuth/Token' => Http::response(['error' => 'invalid_client'], 401),
        ]);

        $this->expectException(\Exception::class);

        (new MicroBiltService)->authenticate();
    }

    /**
     * Known finding (documented, not fixed here per task instructions):
     * MicroBiltService::authenticate() logs the plaintext client_secret via
     * Log::info('MicroBilt Auth Attempt Credentials', ['client_secret' => ...]).
     * This test asserts that the leak is CURRENTLY happening, as a flagged
     * regression marker rather than a silent pass.
     */
    public function test_authenticate_currently_logs_the_plaintext_client_secret_flagged_finding(): void
    {
        Http::fake([
            'https://microbilt.test/OAuth/Token' => Http::response(['access_token' => 'abc123', 'expires_in' => 3600], 200),
        ]);

        Log::spy();

        (new MicroBiltService)->authenticate();

        Log::shouldHaveReceived('info')
            ->withArgs(function ($message, $context) {
                return $message === 'MicroBilt Auth Attempt Credentials'
                    && ($context['client_secret'] ?? null) === 'super-secret-value';
            })
            ->once();
    }

    public function test_ein_verification_uses_the_busrvtxid_product_id_and_correct_payload_shape(): void
    {
        Http::fake([
            'https://microbilt.test/OAuth/Token' => Http::response(['access_token' => 'abc123', 'expires_in' => 3600], 200),
            'https://microbilt.test/MBBusinessReport/GetReport' => Http::response(['status' => 'ok'], 200),
        ]);

        (new MicroBiltService)->einVerification('Acme Inc', '12-3456789');

        Http::assertSent(function ($request) {
            return $request->url() === 'https://microbilt.test/MBBusinessReport/GetReport'
                && $request['MsgRqHdr']['ProductID'] === 'BUSRVTXID'
                && $request['CorporateDetails']['CompanyName'] === 'Acme Inc'
                && $request['FEIN'] === '12-3456789';
        });
    }

    public function test_business_address_verification_uses_the_bussrch_product_id(): void
    {
        Http::fake([
            'https://microbilt.test/OAuth/Token' => Http::response(['access_token' => 'abc123', 'expires_in' => 3600], 200),
            'https://microbilt.test/MBBusinessReport/GetReport' => Http::response(['status' => 'ok'], 200),
        ]);

        (new MicroBiltService)->businessAddressVerification('Acme Inc', '123 Main St', 'Springfield', 'IL', '62701');

        Http::assertSent(function ($request) {
            if ($request->url() !== 'https://microbilt.test/MBBusinessReport/GetReport') {
                return false;
            }
            $addr = $request['CorporateDetails']['CorporateAddress'][0] ?? [];

            return $request['MsgRqHdr']['ProductID'] === 'BUSSRCH'
                && $addr['Addr1'] === '123 Main St'
                && $addr['City'] === 'Springfield'
                && $addr['StateProv'] === 'IL'
                && $addr['PostalCode'] === '62701';
        });
    }

    public function test_business_phone_verification_uses_the_bussrch_product_id(): void
    {
        Http::fake([
            'https://microbilt.test/OAuth/Token' => Http::response(['access_token' => 'abc123', 'expires_in' => 3600], 200),
            'https://microbilt.test/MBBusinessReport/GetReport' => Http::response(['status' => 'ok'], 200),
        ]);

        (new MicroBiltService)->businessPhoneVerification('Acme Inc', '5551234567');

        Http::assertSent(function ($request) {
            if ($request->url() !== 'https://microbilt.test/MBBusinessReport/GetReport') {
                return false;
            }

            return $request['MsgRqHdr']['ProductID'] === 'BUSSRCH'
                && $request['CorporateDetails']['ContactInfo']['Phone']['PhoneInfo']['PhoneNumber'] === '5551234567';
        });
    }

    public function test_business_identity_verification_uses_the_bussrch_product_id_with_fein(): void
    {
        Http::fake([
            'https://microbilt.test/OAuth/Token' => Http::response(['access_token' => 'abc123', 'expires_in' => 3600], 200),
            'https://microbilt.test/MBBusinessReport/GetReport' => Http::response(['status' => 'ok'], 200),
        ]);

        (new MicroBiltService)->businessIdentityVerification('Acme Inc', '12-3456789', 'Springfield', 'IL');

        Http::assertSent(function ($request) {
            if ($request->url() !== 'https://microbilt.test/MBBusinessReport/GetReport') {
                return false;
            }
            $addr = $request['CorporateDetails']['CorporateAddress'][0] ?? [];

            return $request['MsgRqHdr']['ProductID'] === 'BUSSRCH'
                && $request['FEIN'] === '12-3456789'
                && $addr['City'] === 'Springfield'
                && $addr['StateProv'] === 'IL';
        });
    }

    public function test_active_legal_entity_verification_uses_the_bussos_product_id(): void
    {
        Http::fake([
            'https://microbilt.test/OAuth/Token' => Http::response(['access_token' => 'abc123', 'expires_in' => 3600], 200),
            'https://microbilt.test/MBBusinessReport/GetReport' => Http::response(['status' => 'ok'], 200),
        ]);

        (new MicroBiltService)->activeLegalEntityVerification('Acme Inc', '12-3456789');

        Http::assertSent(function ($request) {
            if ($request->url() !== 'https://microbilt.test/MBBusinessReport/GetReport') {
                return false;
            }

            return $request['MsgRqHdr']['ProductID'] === 'BUSSOS'
                && $request['FEIN'] === '12-3456789';
        });
    }

    /**
     * Known finding (documented, not fixed here per task instructions): the
     * repo's CLAUDE.md domain map describes sendRequest()'s retry(3, ..., 100)
     * as "robust transient error handling", but Laravel's retry() helper only
     * re-invokes its callback when the callback THROWS. Http::post() does not
     * throw on a non-2xx response (only ->throw() does), so a 500/4xx response
     * is returned normally on the first attempt and retry() never retries it —
     * only genuine transport-level exceptions (timeouts, connection errors)
     * would trigger a second attempt. This test characterizes that actual
     * (non-retrying) behavior for HTTP-level failures.
     */
    public function test_send_request_does_not_actually_retry_a_failed_http_response_only_a_single_attempt_is_made(): void
    {
        Http::fake([
            'https://microbilt.test/OAuth/Token' => Http::response(['access_token' => 'abc123', 'expires_in' => 3600], 200),
            'https://microbilt.test/MBBusinessReport/GetReport' => Http::sequence()
                ->push(['error' => 'server error'], 500)
                ->push(['status' => 'ok'], 200)
                ->push(['status' => 'ok'], 200),
        ]);

        $this->expectException(\Exception::class);

        try {
            (new MicroBiltService)->einVerification('Acme Inc', '12-3456789');
        } finally {
            // Only 1 auth call + 1 attempt against the report endpoint — the
            // queued 200 responses are never consumed because retry() gave up
            // after the very first (non-exception) 500 response.
            Http::assertSentCount(2);
        }
    }

    public function test_send_request_throws_after_exhausting_all_retries(): void
    {
        Http::fake([
            'https://microbilt.test/OAuth/Token' => Http::response(['access_token' => 'abc123', 'expires_in' => 3600], 200),
            'https://microbilt.test/MBBusinessReport/GetReport' => Http::response(['error' => 'server error'], 500),
        ]);

        $this->expectException(\Exception::class);

        (new MicroBiltService)->einVerification('Acme Inc', '12-3456789');
    }
}
