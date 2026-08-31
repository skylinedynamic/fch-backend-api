<?php

namespace Tests\Feature\Auth;

use App\Models\Borrower\Borrower;
use App\Models\User\User;
use Laravel\Passport\Token;
use Tests\TestCase;

/**
 * Functional/HTTP coverage of both logout endpoints:
 * POST /api/v1/auth/logout (auth:api, any Admin/Partner/... user)
 * POST /api/v1/borrower/logout (auth:api + borrower)
 */
class LogoutTest extends TestCase
{
    public function test_admin_logout_revokes_the_bearer_token(): void
    {
        $user = User::factory()->admin()->create();
        $tokenResult = $user->createToken('test-token');

        $response = $this->withHeader('Authorization', 'Bearer '.$tokenResult->accessToken)
            ->postJson('/api/v1/auth/logout');

        $response->assertOk()->assertJsonPath('status', true);

        $this->assertTrue((bool) Token::find($tokenResult->getToken()->id)->revoked);
    }

    public function test_admin_logout_without_a_token_is_unauthorized(): void
    {
        $response = $this->postJson('/api/v1/auth/logout');

        $response->assertStatus(401);
    }

    public function test_a_revoked_token_cannot_authenticate_a_fresh_request(): void
    {
        // Revoke directly (rather than via a first postJson() call in this same
        // test) to avoid Laravel's TokenGuard caching the resolved user on its
        // guard instance across two internal HTTP calls within one test method —
        // that reuse is a test-harness artifact, not how separate real requests
        // behave. See test_admin_logout_revokes_the_bearer_token for proof that
        // logout() itself does set revoked=true on the correct token row.
        $user = User::factory()->admin()->create();
        $tokenResult = $user->createToken('test-token');
        $token = $tokenResult->getToken();
        $token->revoked = true;
        $token->save();

        $response = $this->withHeader('Authorization', 'Bearer '.$tokenResult->accessToken)
            ->postJson('/api/v1/auth/logout');

        $response->assertStatus(401);
    }

    public function test_borrower_logout_revokes_the_bearer_token_and_is_gated_to_borrowers(): void
    {
        $borrower = Borrower::factory()->create();
        $user = User::factory()->borrower($borrower)->create();
        $tokenResult = $user->createToken('BorrowerApp');

        $response = $this->withHeader('Authorization', 'Bearer '.$tokenResult->accessToken)
            ->postJson('/api/v1/borrower/logout');

        $response->assertOk()->assertJsonPath('status', true);
        $this->assertTrue((bool) Token::find($tokenResult->getToken()->id)->revoked);
    }

    public function test_borrower_logout_route_rejects_a_non_borrower_user_type(): void
    {
        $user = User::factory()->admin()->create();
        $tokenResult = $user->createToken('test-token');

        $response = $this->withHeader('Authorization', 'Bearer '.$tokenResult->accessToken)
            ->postJson('/api/v1/borrower/logout');

        $response->assertStatus(403);
    }
}
