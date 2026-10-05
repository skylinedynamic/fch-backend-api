<?php

namespace Tests\Feature\TwoFaAuth;

use App\Models\Borrower\Borrower;
use App\Models\Partner\Partner;
use App\Models\Setting\SmsConfiguration;
use App\Models\TwoFaAuth\TwoFaAuthSetting;
use App\Models\User\User;
use App\Services\Global\TwoFaAuth\TwoFaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Two-factor codes by SMS go out through the same Settings > SMS
 * Configuration as every other text: the user's partner's account (white-label
 * partners) or the host's (everyone else), and none at all while that account
 * is not enabled.
 *
 * Unlike notifications, a 2FA code is sent to an unverified phone: proving the
 * number is the point of the code.
 */
class TwoFaSmsTest extends TestCase
{
    use RefreshDatabase;

    private function partner(bool $isHost, string $type, string $email): Partner
    {
        return Partner::create([
            'is_host' => $isHost,
            'status' => Partner::STATUS_ACTIVE,
            'partner_type' => $type,
            'company_name' => $email,
            'full_name' => $email,
            'email' => $email,
        ]);
    }

    private function configure(Partner $partner, bool $enabled, string $sid): void
    {
        SmsConfiguration::create([
            'partner_id' => $partner->id,
            'country' => 'USA',
            'provider' => 'twilio',
            'is_enabled' => $enabled,
            'from_number' => '+15005550006',
            'credentials' => ['account_sid' => $sid, 'auth_token' => 'tok'],
        ]);
    }

    private function userOf(Partner $partner, ?string $phone = '+15551234567'): User
    {
        return User::create([
            'name' => 'Pat',
            'email' => 'pat-' . uniqid() . '@example.test',
            'password' => bcrypt('password'),
            'status' => true,
            'partner_id' => $partner->id,
            'phone_number' => $phone,
        ]);
    }

    private function startSmsSetup(User $user): array
    {
        Auth::login($user);

        return app(TwoFaService::class)->setup(TwoFaAuthSetting::SMS);
    }

    #[Test]
    public function the_code_is_texted_from_the_white_label_partners_own_account(): void
    {
        Http::fake(['api.twilio.com/*' => Http::response(['sid' => 'SM1'], 201)]);
        $host = $this->partner(true, Partner::PARTNER_TYPE_WHITE_LABEL, 'host@example.test');
        $wlp = $this->partner(false, Partner::PARTNER_TYPE_WHITE_LABEL, 'wlp@example.test');
        $this->configure($host, true, 'AC-host');
        $this->configure($wlp, true, 'AC-wlp');

        $result = $this->startSmsSetup($this->userOf($wlp));

        $this->assertTrue($result['status']);
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/Accounts/AC-wlp/')
            && $request['To'] === '+15551234567'
            && preg_match('/\b\d{6}\b/', $request['Body']) === 1);
    }

    #[Test]
    public function a_certified_partners_user_is_texted_from_the_hosts_account(): void
    {
        Http::fake(['api.twilio.com/*' => Http::response(['sid' => 'SM1'], 201)]);
        $host = $this->partner(true, Partner::PARTNER_TYPE_WHITE_LABEL, 'host@example.test');
        $certified = $this->partner(false, Partner::PARTNER_TYPE_CERTIFIED, 'certified@example.test');
        $this->configure($host, true, 'AC-host');

        $result = $this->startSmsSetup($this->userOf($certified));

        $this->assertTrue($result['status']);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/Accounts/AC-host/'));
    }

    #[Test]
    public function no_code_is_sent_while_the_users_account_is_not_enabled(): void
    {
        Http::fake();
        $host = $this->partner(true, Partner::PARTNER_TYPE_WHITE_LABEL, 'host@example.test');
        $wlp = $this->partner(false, Partner::PARTNER_TYPE_WHITE_LABEL, 'wlp@example.test');
        $certified = $this->partner(false, Partner::PARTNER_TYPE_CERTIFIED, 'certified@example.test');
        $this->configure($host, false, 'AC-host');
        $this->configure($wlp, false, 'AC-wlp');

        $this->assertFalse($this->startSmsSetup($this->userOf($wlp))['status']);
        $this->assertFalse($this->startSmsSetup($this->userOf($certified))['status']);
        Http::assertNothingSent();
    }

    #[Test]
    public function a_user_without_a_phone_number_gets_no_code(): void
    {
        Http::fake();
        $host = $this->partner(true, Partner::PARTNER_TYPE_WHITE_LABEL, 'host@example.test');
        $this->configure($host, true, 'AC-host');

        $this->assertFalse($this->startSmsSetup($this->userOf($host, phone: null))['status']);
        Http::assertNothingSent();
    }

    #[Test]
    public function the_code_goes_to_the_normalised_number(): void
    {
        Http::fake(['api.twilio.com/*' => Http::response(['sid' => 'SM1'], 201)]);
        $host = $this->partner(true, Partner::PARTNER_TYPE_WHITE_LABEL, 'host@example.test');
        $this->configure($host, true, 'AC-host');

        $this->startSmsSetup($this->userOf($host, phone: '(555) 123-4567'));

        Http::assertSent(fn ($request) => $request['To'] === '+15551234567');
    }

    #[Test]
    public function a_borrowers_code_goes_to_the_borrowers_phone_with_its_country_code(): void
    {
        Http::fake(['api.twilio.com/*' => Http::response(['sid' => 'SM1'], 201)]);
        $host = $this->partner(true, Partner::PARTNER_TYPE_WHITE_LABEL, 'host@example.test');
        $this->configure($host, true, 'AC-host');
        $borrower = Borrower::create([
            'partner_id' => $host->id,
            'status' => Borrower::STATUS_ACTIVE,
            'first_name' => 'Bo',
            'last_name' => 'Rower',
            'email' => 'bo@example.test',
            'phone_number' => '(630) 660-9514',
            'country_code' => '+1',
        ]);
        $user = $this->userOf($host, phone: '+15551234567');
        $user->forceFill(['entity_type' => Borrower::class, 'entity_id' => $borrower->id])->save();

        $this->startSmsSetup($user);

        Http::assertSent(fn ($request) => $request['To'] === '+16306609514');
    }

    #[Test]
    public function a_connection_failure_is_reported_as_a_failed_send_not_an_error(): void
    {
        Http::fake(fn () => throw new ConnectionException('timed out'));
        $host = $this->partner(true, Partner::PARTNER_TYPE_WHITE_LABEL, 'host@example.test');
        $this->configure($host, true, 'AC-host');

        $this->assertFalse($this->startSmsSetup($this->userOf($host))['status']);
    }

    #[Test]
    public function a_provider_rejection_is_reported_as_a_failed_send(): void
    {
        Http::fake(['api.twilio.com/*' => Http::response(['message' => 'Authenticate'], 401)]);
        $host = $this->partner(true, Partner::PARTNER_TYPE_WHITE_LABEL, 'host@example.test');
        $this->configure($host, true, 'AC-host');

        $this->assertFalse($this->startSmsSetup($this->userOf($host))['status']);
    }
}
