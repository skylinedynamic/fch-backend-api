<?php

namespace Tests\Feature\Auth;

use App\Models\Borrower\Borrower;
use App\Models\User\User;
use Tests\Feature\Auth\Support\BypassesPortalIsolation;
use Tests\TestCase;

/**
 * Functional/HTTP coverage of POST /api/v1/borrower/login, which routes to
 * App\Http\Controllers\Api\V1\Borrower\Auth\AuthController::login() — this
 * always calls AuthService::login() with the explicit User::BORROWER type.
 */
class BorrowerLoginTest extends TestCase
{
    use BypassesPortalIsolation;

    public function test_borrower_can_log_in_with_valid_credentials(): void
    {
        $this->bypassPortalIsolation(true);
        $borrower = Borrower::factory()->create();
        $user = User::factory()->borrower($borrower)->create(['email' => 'borrower-login@example.com']);

        $response = $this->postJson('/api/v1/borrower/login', [
            'email' => 'borrower-login@example.com',
            'password' => 'password',
        ]);

        $response->assertOk()
            ->assertJsonPath('status', true)
            ->assertJsonPath('data.user.id', $user->id);
    }

    public function test_admin_type_user_cannot_log_in_through_the_borrower_endpoint(): void
    {
        $this->bypassPortalIsolation(true);
        User::factory()->admin()->create(['email' => 'admin-on-borrower@example.com']);

        $response = $this->postJson('/api/v1/borrower/login', [
            'email' => 'admin-on-borrower@example.com',
            'password' => 'password',
        ]);

        $response->assertStatus(401)->assertJsonPath('field', 'email');
    }

    public function test_login_fails_with_invalid_password(): void
    {
        $this->bypassPortalIsolation(true);
        $borrower = Borrower::factory()->create();
        User::factory()->borrower($borrower)->create(['email' => 'borrower-wrongpass@example.com']);

        $response = $this->postJson('/api/v1/borrower/login', [
            'email' => 'borrower-wrongpass@example.com',
            'password' => 'not-the-password',
        ]);

        $response->assertStatus(401)->assertJsonPath('field', 'password');
    }

    public function test_login_validation_requires_email_and_password(): void
    {
        $response = $this->postJson('/api/v1/borrower/login', []);

        $response->assertStatus(422);
        $data = $response->json();
        $this->assertIsString($data['errors']['email']);
        $this->assertIsString($data['errors']['password']);
    }

    public function test_registration_route_is_not_currently_exposed(): void
    {
        // routes/borrower.php has the /register route commented out — confirm
        // that stays true rather than silently reappearing.
        $response = $this->postJson('/api/v1/borrower/register', []);

        $response->assertStatus(404);
    }
}
