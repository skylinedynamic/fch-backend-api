<?php

namespace Tests\Unit\Services\Global\Auth;

use App\Models\Borrower\Borrower;
use App\Models\Employee\Employee;
use App\Models\Partner\Partner;
use App\Models\TwoFaAuth\TwoFaAuthSetting;
use App\Models\User\User;
use App\Services\Global\Auth\AuthService;
use App\Services\Global\Common\PortalIsolationService;
use App\Services\Global\Notification\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Passport\AccessToken;
use Tests\TestCase;

class AuthServiceTest extends TestCase
{
    protected AuthService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new AuthService;

        // Domain isolation is not what this test class is about — bypass it
        // by default so login logic can be exercised in isolation. The one
        // integration test below rebinds a denying double explicitly.
        $this->bindPortalIsolation(true);
    }

    private function bindPortalIsolation(bool $allowed): void
    {
        $this->app->bind(PortalIsolationService::class, function () use ($allowed) {
            return new class($allowed) extends PortalIsolationService
            {
                public function __construct(private bool $allowed) {}

                public function isUserAllowedOnDomain(User $user, string $domain): bool
                {
                    return $this->allowed;
                }
            };
        });
    }

    private function request(): Request
    {
        return Request::create('/v1/auth/login', 'POST');
    }

    public function test_login_succeeds_with_valid_credentials_and_returns_token(): void
    {
        $user = User::factory()->admin()->create([
            'email' => 'admin@example.com',
        ]);

        $response = $this->service->login([
            'email' => 'admin@example.com',
            'password' => 'password',
        ], $this->request());

        $this->assertSame(200, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);

        $this->assertTrue($data['status']);
        $this->assertNotEmpty($data['data']['token']);
        $this->assertSame($user->id, $data['data']['user']['id']);
        $this->assertSame(__('auth.logged_in'), $data['message']);
    }

    public function test_login_returns_two_factor_challenge_when_totp_enabled_without_issuing_token(): void
    {
        $user = User::factory()->admin()->create(['email' => 'totp@example.com']);
        TwoFaAuthSetting::factory()->totpEnabled()->create(['user_id' => $user->id]);

        $response = $this->service->login([
            'email' => 'totp@example.com',
            'password' => 'password',
        ], $this->request());

        $this->assertSame(200, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);

        $this->assertTrue($data['status']);
        $this->assertSame($user->id, $data['data']['user_id']);
        $this->assertTrue($data['data']['two_factor_enabled']);
        $this->assertSame(TwoFaAuthSetting::TOTP, $data['data']['two_factor_method']);
        $this->assertArrayNotHasKey('token', $data['data']);
    }

    public function test_login_with_sms_two_factor_triggers_resend_via_notification_service(): void
    {
        $user = User::factory()->admin()->create(['email' => 'sms@example.com']);
        TwoFaAuthSetting::factory()->create([
            'user_id' => $user->id,
            'enabled' => true,
            'method' => TwoFaAuthSetting::SMS,
            'confirmed_at' => now(),
        ]);

        $mockNotification = \Mockery::mock(NotificationService::class);
        $mockNotification->shouldReceive('send')
            ->once()
            ->withArgs(function ($u, $notification, $channel) use ($user) {
                return $u->id === $user->id && $channel === 'sms';
            })
            ->andReturn(true);
        $this->app->instance(NotificationService::class, $mockNotification);

        $response = $this->service->login([
            'email' => 'sms@example.com',
            'password' => 'password',
        ], $this->request());

        $this->assertSame(200, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertSame(TwoFaAuthSetting::SMS, $data['data']['two_factor_method']);
    }

    public function test_login_fails_with_unknown_email_and_increments_rate_limiter(): void
    {
        $response = $this->service->login([
            'email' => 'nobody@example.com',
            'password' => 'password',
        ], $this->request());

        $this->assertSame(401, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);

        $this->assertFalse($data['status']);
        $this->assertSame('email', $data['field']);
        $this->assertSame(__('auth.invalid_email'), $data['message']);
        $this->assertSame(4, $data['remaining_attempts']);
        // max_attempts round-trips as a numeric *string* here because
        // config('app.login_throttle_limit') resolves through env('LOGIN_THROTTLE_LIMIT', 5)
        // and the .env value is a string ("5"), which PHP's arithmetic coerces for
        // remaining_attempts but ApiResponse::loginError() passes through as-is for
        // max_attempts — so the JSON field is "5", not 5. Documented, not asserted as ideal.
        $this->assertEquals(config('app.login_throttle_limit'), $data['max_attempts']);
    }

    public function test_login_fails_with_wrong_password_and_increments_rate_limiter(): void
    {
        User::factory()->admin()->create(['email' => 'wrongpass@example.com']);

        $response = $this->service->login([
            'email' => 'wrongpass@example.com',
            'password' => 'not-the-password',
        ], $this->request());

        $this->assertSame(401, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);

        $this->assertFalse($data['status']);
        $this->assertSame('password', $data['field']);
        $this->assertSame(__('auth.invalid_password'), $data['message']);
        $this->assertSame(4, $data['remaining_attempts']);
        $this->assertEquals(config('app.login_throttle_limit'), $data['max_attempts']);
    }

    public function test_login_is_rate_limited_after_max_attempts(): void
    {
        User::factory()->admin()->create(['email' => 'ratelimited@example.com']);
        $request = $this->request();

        for ($i = 0; $i < 5; $i++) {
            $this->service->login([
                'email' => 'ratelimited@example.com',
                'password' => 'wrong-password',
            ], $request);
        }

        $response = $this->service->login([
            'email' => 'ratelimited@example.com',
            'password' => 'wrong-password',
        ], $request);

        $this->assertSame(429, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertFalse($data['status']);
        $this->assertSame(__('auth.too_many_login_attempts', ['time' => config('app.login_decay_minutes')]), $data['message']);
    }

    public function test_login_rejects_inactive_user(): void
    {
        $user = User::factory()->admin()->inactive()->create(['email' => 'inactive@example.com']);

        $response = $this->service->login([
            'email' => 'inactive@example.com',
            'password' => 'password',
        ], $this->request());

        $this->assertSame(401, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertSame(__('auth.user_inactive'), $data['message']);
    }

    /**
     * KNOWN PRODUCTION BUG (documented, not fixed — see task report):
     * AuthService::login() gates entity status with
     * `$user->entity_type == User::PARTNER` (i.e. == 'Partner'), but entity_type
     * is actually stored as the fully-qualified class name (App\Models\Partner\Partner)
     * everywhere in this codebase (factories, BorrowerAuthService::registerBorrower(),
     * and User::getEntityImage()'s own `$this->entity_type === Borrower::class` check).
     * No morph map is registered. So `'App\Models\Partner\Partner' == 'Partner'` is
     * always false, and the suspended/inactive checks for Partner/Employee/Borrower
     * entities below are unreachable dead code — a suspended or inactive Partner,
     * Employee, or Borrower can still log in successfully today. These tests
     * characterize that real (buggy) behavior; they intentionally assert success.
     */
    public function test_login_does_not_actually_reject_suspended_partner_entity_due_to_entity_type_comparison_bug(): void
    {
        $partner = Partner::factory()->suspended()->create();
        User::factory()->partner($partner)->create(['email' => 'suspendedpartner@example.com']);

        $response = $this->service->login([
            'email' => 'suspendedpartner@example.com',
            'password' => 'password',
        ], $this->request());

        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_login_does_not_actually_reject_inactive_partner_entity_due_to_entity_type_comparison_bug(): void
    {
        $partner = Partner::factory()->inactive()->create();
        User::factory()->partner($partner)->create(['email' => 'inactivepartner@example.com']);

        $response = $this->service->login([
            'email' => 'inactivepartner@example.com',
            'password' => 'password',
        ], $this->request());

        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_login_does_not_actually_reject_suspended_employee_entity_due_to_entity_type_comparison_bug(): void
    {
        $employee = Employee::factory()->suspended()->create();
        User::factory()->employee($employee)->create(['email' => 'suspendedemployee@example.com']);

        // Employee-type users are never found by the default whereIn([Admin, Partner])
        // lookup, so the explicit user_type must be passed to reach the (dead) status gate.
        $response = $this->service->login([
            'email' => 'suspendedemployee@example.com',
            'password' => 'password',
        ], $this->request(), User::EMPLOYEE);

        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_login_does_not_actually_reject_inactive_employee_entity_due_to_entity_type_comparison_bug(): void
    {
        $employee = Employee::factory()->inactive()->create();
        User::factory()->employee($employee)->create(['email' => 'inactiveemployee@example.com']);

        $response = $this->service->login([
            'email' => 'inactiveemployee@example.com',
            'password' => 'password',
        ], $this->request(), User::EMPLOYEE);

        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_login_does_not_actually_reject_suspended_borrower_entity_due_to_entity_type_comparison_bug(): void
    {
        $borrower = Borrower::factory()->suspended()->create();
        User::factory()->borrower($borrower)->create(['email' => 'suspendedborrower@example.com']);

        $response = $this->service->login([
            'email' => 'suspendedborrower@example.com',
            'password' => 'password',
        ], $this->request(), User::BORROWER);

        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_login_does_not_actually_reject_inactive_borrower_entity_due_to_entity_type_comparison_bug(): void
    {
        $borrower = Borrower::factory()->inactive()->create();
        User::factory()->borrower($borrower)->create(['email' => 'inactiveborrower@example.com']);

        $response = $this->service->login([
            'email' => 'inactiveborrower@example.com',
            'password' => 'password',
        ], $this->request(), User::BORROWER);

        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_login_respects_explicit_user_type_filter(): void
    {
        // Same email used by both an Admin and (separately) a Borrower would collide on
        // the unique email constraint, so instead prove the filter by using a type that
        // would NOT be found under the default whereIn([Admin, Partner]) filter.
        $borrower = Borrower::factory()->create();
        User::factory()->borrower($borrower)->create(['email' => 'typed-borrower@example.com']);

        // Without an explicit type, borrower accounts are invisible to login() because
        // it defaults to whereIn([Admin, Partner]).
        $response = $this->service->login([
            'email' => 'typed-borrower@example.com',
            'password' => 'password',
        ], $this->request());
        $this->assertSame(401, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertSame(__('auth.invalid_email'), $data['message']);

        // With the explicit Borrower type, the same account is found and logs in.
        $response = $this->service->login([
            'email' => 'typed-borrower@example.com',
            'password' => 'password',
        ], $this->request(), User::BORROWER);
        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_login_denied_when_portal_isolation_service_rejects_domain(): void
    {
        $this->bindPortalIsolation(false);
        User::factory()->admin()->create(['email' => 'walledoff@example.com']);

        $response = $this->service->login([
            'email' => 'walledoff@example.com',
            'password' => 'password',
        ], $this->request());

        $this->assertSame(401, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertSame(__('auth.invalid_email'), $data['message']);
        $this->assertSame('email', $data['field']);
    }

    public function test_logout_revokes_the_current_access_token(): void
    {
        $user = User::factory()->admin()->create();
        $tokenResult = $user->createToken('test-token');
        $passportToken = $tokenResult->getToken();

        // HasApiTokens::token() returns whatever ScopeAuthorizable was attached via
        // withAccessToken() — in real requests that's Passport's AccessToken wrapper
        // (set by TokenGuard from the bearer JWT), not the raw Token Eloquent model.
        $user->withAccessToken(new AccessToken([
            'oauth_access_token_id' => $passportToken->id,
        ]));

        Auth::shouldUse('api');
        Auth::guard('api')->setUser($user);

        $response = $this->service->logout();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue((bool) $passportToken->fresh()->revoked);
    }

    public function test_logout_without_authenticated_user_returns_unauthorized(): void
    {
        $response = $this->service->logout();

        $this->assertSame(401, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertSame(__('auth.unauthenticated'), $data['message']);
    }

    protected function tearDown(): void
    {
        \Mockery::close();
        parent::tearDown();
    }
}
