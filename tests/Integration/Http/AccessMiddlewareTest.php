<?php

namespace Tests\Integration\Http;

use App\Http\Middleware\AdminMiddleware;
use App\Http\Middleware\BorrowerMiddleware;
use App\Http\Middleware\HostOnly;
use App\Http\Middleware\RejectBorrowersFromPartnerPortal;
use App\Http\Middleware\RememberUserTimezone;
use App\Models\User\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/**
 * The middleware that decides who gets through to a route group, run on its
 * own against each kind of user: the host team, a partner, a borrower, and
 * nobody signed in.
 */
#[CoversClass(AdminMiddleware::class)]
#[CoversClass(BorrowerMiddleware::class)]
#[CoversClass(HostOnly::class)]
#[CoversClass(RejectBorrowersFromPartnerPortal::class)]
#[CoversClass(RememberUserTimezone::class)]
class AccessMiddlewareTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithPortalUsers;

    /** @var array<string, User> */
    private array $users;

    protected function setUp(): void
    {
        parent::setUp();

        $host = $this->makeHostPartner();
        $partner = $this->makeWhiteLabelPartner();
        $this->users = [
            'host admin' => $this->makePartnerAdmin($host),
            'host staff' => $this->makeStaffUser($host),
            'partner admin' => $this->makePartnerAdmin($partner),
            'partner staff' => $this->makeStaffUser($partner),
            'borrower' => $this->makeBorrowerUser($partner),
        ];
    }

    /** Runs $middleware for $user (or nobody) and returns the status - 200 means it let the request through. */
    private function statusFor(object $middleware, ?User $user, ?Request $request = null): int
    {
        $request ??= Request::create('/api/v1/anything');
        $request->setUserResolver(fn () => $user);
        $user ? Auth::setUser($user) : Auth::forgetGuards();

        return $middleware->handle($request, fn () => new Response('through', 200))->getStatusCode();
    }

    /** @param array<string, int> $expected */
    private function assertStatuses(object $middleware, array $expected): void
    {
        foreach ($expected as $who => $status) {
            $this->assertSame($status, $this->statusFor($middleware, $this->users[$who] ?? null), $who);
        }
    }

    #[Test]
    public function the_admin_routes_admit_the_host_team_only(): void
    {
        $this->assertStatuses(new AdminMiddleware(), [
            'host admin' => 200,
            'host staff' => 200,
            'partner admin' => 403,
            'partner staff' => 403,
            'borrower' => 403,
            'nobody' => 401,
        ]);
    }

    #[Test]
    public function the_borrower_routes_admit_borrowers_only(): void
    {
        $this->assertStatuses(new BorrowerMiddleware(), [
            'host admin' => 403,
            'partner admin' => 403,
            'borrower' => 200,
            'nobody' => 401,
        ]);
    }

    #[Test]
    public function host_only_routes_admit_anyone_on_the_host_partner(): void
    {
        $this->assertStatuses(new HostOnly(), [
            'host admin' => 200,
            'host staff' => 200,
            'partner admin' => 403,
            'partner staff' => 403,
            'borrower' => 403,
            'nobody' => 403,
        ]);
    }

    private function requestTo(string $method, string $uri): Request
    {
        $request = Request::create('/' . $uri, $method);
        $request->setRouteResolver(fn () => new RoutingRoute([$method], $uri, fn () => null));

        return $request;
    }

    #[Test]
    public function the_partner_portal_turns_borrowers_away_except_on_their_shared_routes(): void
    {
        $middleware = new RejectBorrowersFromPartnerPortal();
        $shared = $this->requestTo('POST', 'api/v1/common/appointments/fetch-all');
        $partnerOnly = $this->requestTo('POST', 'api/v1/common/borrowers/fetch-borrowers');

        $this->assertSame(200, $this->statusFor($middleware, $this->users['borrower'], $shared));
        $this->assertSame(403, $this->statusFor($middleware, $this->users['borrower'], $partnerOnly));
        // The same verb is part of the match.
        $this->assertSame(403, $this->statusFor($middleware, $this->users['borrower'], $this->requestTo('DELETE', 'api/v1/common/appointments/fetch-all')));

        foreach (['host admin', 'partner admin', 'partner staff'] as $who) {
            $this->assertSame(200, $this->statusFor($middleware, $this->users[$who], $partnerOnly), $who);
        }
        $this->assertSame(200, $this->statusFor($middleware, null, $partnerOnly), 'the auth middleware answers for nobody');
    }

    /**
     * A route renamed or removed without updating the list would lock
     * borrowers out of a screen they use, with nothing failing to say so.
     */
    #[Test]
    public function every_route_borrowers_share_with_the_partner_portal_exists(): void
    {
        $registered = collect(Route::getRoutes()->getRoutes())
            ->flatMap(fn (RoutingRoute $route) => array_map(fn ($method) => $method . ' ' . $route->uri(), $route->methods()))
            ->all();

        foreach (RejectBorrowersFromPartnerPortal::BORROWER_ROUTES as $entry) {
            $this->assertContains($entry, $registered, "{$entry} is not a registered route");
        }
    }

    #[Test]
    public function a_users_timezone_is_remembered_from_a_valid_header(): void
    {
        $user = $this->users['partner admin'];
        $request = Request::create('/api/v1/anything');

        $request->headers->set('X-Timezone', 'America/Chicago');
        $this->statusFor(new RememberUserTimezone(), $user, $request);
        $this->assertSame('America/Chicago', $user->fresh()->timezone);

        $request->headers->set('X-Timezone', 'Mars/Olympus_Mons');
        $this->statusFor(new RememberUserTimezone(), $user, $request);
        $this->assertSame('America/Chicago', $user->fresh()->timezone, 'an unknown zone is ignored');
    }
}
