<?php

namespace Tests\Feature\Auth;

use App\Models\Borrower\Borrower;
use App\Models\Partner\Partner;
use App\Models\TwoFaAuth\TwoFaAuthSetting;
use App\Models\User\User;
use Tests\Feature\Auth\Support\BypassesPortalIsolation;
use Tests\TestCase;

/**
 * Functional/HTTP coverage of POST /api/v1/auth/login, which routes to
 * App\Http\Controllers\Api\V1\FCH\Common\Auth\AuthController::login() — the
 * shared Admin/Partner login endpoint (no explicit user_type, so
 * AuthService::login() defaults to whereIn([Admin, Partner])).
 */
class AdminPartnerLoginTest extends TestCase
{
    use BypassesPortalIsolation;

    public function test_admin_can_log_in_with_valid_credentials(): void
    {
        $this->bypassPortalIsolation(true);
        $user = User::factory()->admin()->create(['email' => 'admin@example.com']);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'admin@example.com',
            'password' => 'password',
        ]);

        $response->assertOk()
            ->assertJsonPath('status', true)
            ->assertJsonPath('data.user.id', $user->id)
            ->assertJsonStructure(['status', 'message', 'data' => ['token', 'user']]);
    }

    public function test_login_fails_with_invalid_password(): void
    {
        $this->bypassPortalIsolation(true);
        User::factory()->admin()->create(['email' => 'admin2@example.com']);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'admin2@example.com',
            'password' => 'wrong-password',
        ]);

        $response->assertStatus(401)
            ->assertJsonPath('status', false)
            ->assertJsonPath('field', 'password');
    }

    public function test_login_is_rate_limited_after_repeated_failures(): void
    {
        $this->bypassPortalIsolation(true);
        User::factory()->admin()->create(['email' => 'ratelimited-http@example.com']);
        $maxAttempts = (int) config('app.login_throttle_limit');

        for ($i = 0; $i < $maxAttempts; $i++) {
            $this->postJson('/api/v1/auth/login', [
                'email' => 'ratelimited-http@example.com',
                'password' => 'wrong-password',
            ]);
        }

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'ratelimited-http@example.com',
            'password' => 'wrong-password',
        ]);

        $response->assertStatus(429)->assertJsonPath('status', false);
    }

    public function test_login_requires_two_factor_confirmation_when_enabled(): void
    {
        $this->bypassPortalIsolation(true);
        $user = User::factory()->admin()->create(['email' => 'twofa-admin@example.com']);
        TwoFaAuthSetting::factory()->totpEnabled()->create(['user_id' => $user->id]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'twofa-admin@example.com',
            'password' => 'password',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.user_id', $user->id)
            ->assertJsonPath('data.two_factor_enabled', true)
            ->assertJsonPath('data.two_factor_method', TwoFaAuthSetting::TOTP);
        $this->assertArrayNotHasKey('token', $response->json('data'));
    }

    public function test_borrower_type_user_cannot_log_in_through_the_admin_partner_endpoint(): void
    {
        $this->bypassPortalIsolation(true);
        $borrower = Borrower::factory()->create();
        User::factory()->borrower($borrower)->create(['email' => 'borrower-on-admin@example.com']);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'borrower-on-admin@example.com',
            'password' => 'password',
        ]);

        $response->assertStatus(401)->assertJsonPath('field', 'email');
    }

    public function test_login_validation_rejects_missing_credentials_with_flattened_422_shape(): void
    {
        $response = $this->postJson('/api/v1/auth/login', []);

        $response->assertStatus(422);
        $data = $response->json();
        $this->assertFalse($data['status']);
        $this->assertIsString($data['errors']['email']);
        $this->assertIsString($data['errors']['password']);
    }

    public function test_domain_isolation_denies_a_host_partner_user_on_a_non_matching_domain(): void
    {
        // No bypassPortalIsolation() call here — exercise the real, container-resolved
        // PortalIsolationService with a real Partner and a non-matching domain.
        $partner = Partner::factory()->host()->create();
        User::factory()->partner($partner)->create(['email' => 'isolated@example.com']);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'isolated@example.com',
            'password' => 'password',
        ], ['X-Domain' => 'totally-unrelated-domain.test']);

        $response->assertStatus(401)->assertJsonPath('field', 'email');
    }

    public function test_domain_isolation_allows_a_host_partner_user_on_the_reserved_partner_portal(): void
    {
        $partner = Partner::factory()->host()->create();
        User::factory()->partner($partner)->create(['email' => 'hostpartner@example.com']);

        $reservedPartnerPortal = config('domains.reserved.base_reserved_partner_portal');

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'hostpartner@example.com',
            'password' => 'password',
        ], ['X-Domain' => $reservedPartnerPortal]);

        $response->assertOk()->assertJsonPath('status', true);
    }
}
