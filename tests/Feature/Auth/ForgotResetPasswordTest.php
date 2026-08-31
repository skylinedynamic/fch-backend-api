<?php

namespace Tests\Feature\Auth;

use App\Models\Partner\Partner;
use App\Models\User\User;
use App\Services\Global\Notification\Mail\GenericNotificationMail;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Tests\Feature\Auth\Support\BypassesPortalIsolation;
use Tests\TestCase;

/**
 * Functional/HTTP coverage of POST /api/v1/auth/forgot and /api/v1/auth/reset
 * (App\Http\Controllers\Api\V1\FCH\Common\Auth\AuthController).
 */
class ForgotResetPasswordTest extends TestCase
{
    use BypassesPortalIsolation;

    public function test_forgot_password_sends_a_reset_link_for_a_known_email(): void
    {
        $this->bypassPortalIsolation(true);
        Mail::fake();
        User::factory()->admin()->create(['email' => 'forgot@example.com']);

        $response = $this->postJson('/api/v1/auth/forgot', ['email' => 'forgot@example.com']);

        $response->assertOk()->assertJsonPath('status', true);
        // User::sendPasswordResetNotification() routes through the app's own
        // NotificationService/EmailChannel (Mail::to()->send(...)), not Laravel's
        // Notification facade, so we assert against Mail::fake() instead.
        Mail::assertSent(GenericNotificationMail::class);
    }

    public function test_forgot_password_for_an_unknown_email_does_not_error(): void
    {
        Mail::fake();

        $response = $this->postJson('/api/v1/auth/forgot', ['email' => 'nobody@example.com']);

        // Password::sendResetLink() with an unknown email returns
        // Password::INVALID_USER, which the controller maps to a 400 error
        // response rather than a 200 — this avoids user enumeration via status
        // code being irrelevant here since the message itself is generic.
        $response->assertStatus(400)->assertJsonPath('status', false);
    }

    public function test_forgot_password_denied_by_domain_isolation_for_a_known_email(): void
    {
        // No bypass — real PortalIsolationService, mismatched domain.
        $partner = Partner::factory()->create();
        User::factory()->partner($partner)->create(['email' => 'walledoff-forgot@example.com']);

        $response = $this->postJson('/api/v1/auth/forgot', [
            'email' => 'walledoff-forgot@example.com',
        ], ['X-Domain' => 'unrelated-domain.test']);

        $response->assertStatus(403);
    }

    public function test_forgot_password_validation_requires_a_valid_email(): void
    {
        $response = $this->postJson('/api/v1/auth/forgot', ['email' => 'not-an-email']);

        $response->assertStatus(422);
        $this->assertIsString($response->json('errors.email'));
    }

    public function test_reset_password_succeeds_with_a_valid_token(): void
    {
        $this->bypassPortalIsolation(true);
        $user = User::factory()->admin()->create(['email' => 'reset@example.com']);
        $token = Password::createToken($user);

        $response = $this->postJson('/api/v1/auth/reset', [
            'token' => $token,
            'email' => 'reset@example.com',
            'password' => 'NewPassword123',
            'password_confirmation' => 'NewPassword123',
        ]);

        $response->assertOk()->assertJsonPath('status', true);

        $loginResponse = $this->postJson('/api/v1/auth/login', [
            'email' => 'reset@example.com',
            'password' => 'NewPassword123',
        ]);
        $loginResponse->assertOk();
    }

    public function test_reset_password_fails_with_an_invalid_token(): void
    {
        $this->bypassPortalIsolation(true);
        User::factory()->admin()->create(['email' => 'badtoken@example.com']);

        $response = $this->postJson('/api/v1/auth/reset', [
            'token' => 'not-a-real-token',
            'email' => 'badtoken@example.com',
            'password' => 'NewPassword123',
            'password_confirmation' => 'NewPassword123',
        ]);

        $response->assertStatus(400)->assertJsonPath('status', false);
    }

    public function test_reset_password_validation_enforces_password_complexity_and_confirmation(): void
    {
        $response = $this->postJson('/api/v1/auth/reset', [
            'token' => 'irrelevant',
            'email' => 'someone@example.com',
            'password' => 'weak',
            'password_confirmation' => 'weak',
        ]);

        $response->assertStatus(422);
        $this->assertIsString($response->json('errors.password'));
    }
}
