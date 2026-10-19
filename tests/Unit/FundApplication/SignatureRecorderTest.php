<?php

namespace Tests\Unit\FundApplication;

use App\Models\User\User;
use App\Services\FCH\Common\Application\SignatureRecorder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * What is recorded when a funding application is signed - in the borrower
 * portal, the partner portal or on the public embedded form - and so what the
 * electronic signature certificate can prove later.
 */
class SignatureRecorderTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function request(string $ip = '203.0.113.9'): Request
    {
        return Request::create('/', 'POST', [], [], [], [
            'REMOTE_ADDR' => $ip,
            'HTTP_USER_AGENT' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/141.0.0.0 Safari/537.36',
        ]);
    }

    private function signature(array $overrides = []): array
    {
        return array_merge([
            'signature_name' => 'Ava Ortiz',
            'timezone' => 'America/Chicago',
            'esign_consent' => true,
            'terms_consent' => true,
            'credit_consent' => true,
            'legal_binding_consent' => true,
        ], $overrides);
    }

    private function borrowerAccount(): User
    {
        $user = new User(['name' => 'Ava Ortiz', 'email' => 'ava@example.test', 'user_type' => User::BORROWER]);
        $user->id = 41;

        return $user;
    }

    #[Test]
    public function the_signers_own_ip_address_is_recorded(): void
    {
        $record = SignatureRecorder::record($this->request('198.51.100.23'), SignatureRecorder::METHOD_BORROWER_PORTAL, $this->signature(), $this->borrowerAccount());

        $this->assertSame('198.51.100.23', $record['ip_address']);
    }

    #[Test]
    public function the_moment_of_signing_is_kept_in_utc_with_the_signers_timezone(): void
    {
        Carbon::setTestNow('2026-10-16 14:41:07');

        $record = SignatureRecorder::record($this->request(), SignatureRecorder::METHOD_BORROWER_PORTAL, $this->signature(), $this->borrowerAccount());

        $this->assertSame('2026-10-16T14:41:07+00:00', $record['signed_at']);
        $this->assertSame('America/Chicago', $record['timezone']);
    }

    #[Test]
    public function a_timezone_that_is_not_one_is_not_kept(): void
    {
        $record = SignatureRecorder::record($this->request(), SignatureRecorder::METHOD_BORROWER_PORTAL, $this->signature(['timezone' => 'Mars/Olympus']), $this->borrowerAccount());

        $this->assertNull($record['timezone']);
    }

    #[Test]
    public function a_portal_signature_names_the_account_that_signed(): void
    {
        $record = SignatureRecorder::record($this->request(), SignatureRecorder::METHOD_BORROWER_PORTAL, $this->signature(), $this->borrowerAccount());

        $this->assertSame(SignatureRecorder::METHOD_BORROWER_PORTAL, $record['signing_method']);
        $this->assertSame(['user_id' => 41, 'name' => 'Ava Ortiz', 'email' => 'ava@example.test', 'user_type' => User::BORROWER], $record['signer_account']);
        $this->assertSame('Ava Ortiz', $record['signature_name']);
    }

    #[Test]
    public function an_embedded_form_signature_has_no_account_only_the_email_given(): void
    {
        $record = SignatureRecorder::record($this->request(), SignatureRecorder::METHOD_EMBEDDED_FORM, $this->signature(), null, 'ava@example.test');

        $this->assertSame(SignatureRecorder::METHOD_EMBEDDED_FORM, $record['signing_method']);
        $this->assertNull($record['signer_account']);
        $this->assertSame('ava@example.test', $record['signer_email']);
    }

    #[Test]
    public function the_policy_versions_come_from_the_server_not_the_browser(): void
    {
        $record = SignatureRecorder::record(
            $this->request(),
            SignatureRecorder::METHOD_BORROWER_PORTAL,
            $this->signature(['terms_version' => '9.9']),
            $this->borrowerAccount()
        );

        $policies = config('funding_application.signing.policies');
        $this->assertSame(array_keys($policies), array_keys($record['policy_versions']));
        foreach ($policies as $key => $policy) {
            $this->assertSame($policy['version'], $record['policy_versions'][$key]['version']);
            $this->assertSame($policy['label'], $record['policy_versions'][$key]['label']);
        }
        $this->assertArrayNotHasKey('terms_version', $record);
    }

    #[Test]
    public function each_consent_is_kept_as_given(): void
    {
        $record = SignatureRecorder::record($this->request(), SignatureRecorder::METHOD_BORROWER_PORTAL, $this->signature(['credit_consent' => '0']), $this->borrowerAccount());

        $this->assertTrue($record['esign_consent']);
        $this->assertTrue($record['terms_consent']);
        $this->assertFalse($record['credit_consent']);
        $this->assertTrue($record['legal_binding_consent']);
    }

    #[Test]
    public function the_device_is_read_from_the_user_agent(): void
    {
        $record = SignatureRecorder::record($this->request(), SignatureRecorder::METHOD_BORROWER_PORTAL, $this->signature(), $this->borrowerAccount());

        $this->assertSame('Chrome', $record['browser_name']);
        $this->assertSame('Windows', $record['operating_system']);
        $this->assertSame('Desktop', $record['device_type']);
    }
}
