<?php

namespace Tests\Unit\Services;

use App\Models\Borrower\Borrower;
use App\Models\Employee\Employee;
use App\Models\Partner\Partner;
use App\Models\User\User;
use App\Services\Global\Common\PortalUrlResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Which portal a login's links open on. A client uses the borrower portal, on
 * its White-Label partner's own borrower domain when that partner runs one;
 * everyone else uses the partner portal - a White-Label partner's custom
 * domain or its subdomain, a Certified partner's subdomain, and the shared
 * portals for the host. Locally every link goes to the env portals.
 */
#[CoversClass(PortalUrlResolver::class)]
class PortalUrlResolverTest extends TestCase
{
    private const PARTNER_PORTAL = 'https://partner.example.com';
    private const BORROWER_PORTAL = 'https://app.example.com';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.frontend_partner_url' => 'partner.example.com',
            'app.frontend_borrower_url' => 'app.example.com',
        ]);
    }

    private function partner(array $attributes = []): Partner
    {
        return new Partner(array_merge([
            'is_host' => false,
            'partner_type' => Partner::PARTNER_TYPE_WHITE_LABEL,
            'active_custom_url' => false,
            'partner_domain' => null,
            'partner_subdomain' => null,
            'borrower_domain' => null,
        ], $attributes));
    }

    private function host(): Partner
    {
        return $this->partner(['is_host' => true, 'partner_domain' => 'host.example.com', 'partner_subdomain' => 'host-sub.example.com']);
    }

    private function whiteLabel(array $attributes = []): Partner
    {
        return $this->partner(array_merge([
            'active_custom_url' => true,
            'partner_domain' => 'admin.wl.com',
            'partner_subdomain' => 'wl.fourcornerfunding.com',
            'borrower_domain' => 'client.wl.com',
        ], $attributes));
    }

    private function certified(array $attributes = []): Partner
    {
        return $this->partner(array_merge([
            'partner_type' => Partner::PARTNER_TYPE_CERTIFIED,
            'active_custom_url' => true,
            'partner_domain' => 'cp-custom.com',
            'partner_subdomain' => 'cp.fourcornerfunding.com',
            'borrower_domain' => 'client.cp.com',
        ], $attributes));
    }

    private function login(?Partner $partner, string $userType, ?string $entityType): User
    {
        $user = new User(['user_type' => $userType, 'entity_type' => $entityType]);

        return $user->setRelation('partner', $partner);
    }

    private function borrower(?Partner $partner): User
    {
        return $this->login($partner, User::BORROWER, Borrower::class);
    }

    private function url(User $user): string
    {
        return app(PortalUrlResolver::class)->url($user, 'email-change/confirm/1/abc');
    }

    // ---- Borrowers -----------------------------------------------------------

    #[Test]
    public function a_white_label_partners_client_uses_its_borrower_domain(): void
    {
        $this->assertSame('https://client.wl.com/email-change/confirm/1/abc', $this->url($this->borrower($this->whiteLabel())));
    }

    #[Test]
    public function a_white_label_client_uses_the_borrower_portal_when_the_custom_domain_is_off_or_missing(): void
    {
        $this->assertSame(self::BORROWER_PORTAL . '/email-change/confirm/1/abc', $this->url($this->borrower($this->whiteLabel(['active_custom_url' => false]))));
        $this->assertSame(self::BORROWER_PORTAL . '/email-change/confirm/1/abc', $this->url($this->borrower($this->whiteLabel(['borrower_domain' => null]))));
    }

    #[Test]
    public function certified_and_host_clients_use_the_borrower_portal(): void
    {
        $this->assertSame(self::BORROWER_PORTAL . '/email-change/confirm/1/abc', $this->url($this->borrower($this->certified())));
        $this->assertSame(self::BORROWER_PORTAL . '/email-change/confirm/1/abc', $this->url($this->borrower($this->host())));
        $this->assertSame(self::BORROWER_PORTAL . '/email-change/confirm/1/abc', $this->url($this->borrower(null)));
    }

    #[Test]
    public function the_portal_follows_the_entity_type_not_the_user_type(): void
    {
        $wl = $this->whiteLabel();

        // A Borrower entity is a client whatever its user_type says...
        $this->assertSame('https://client.wl.com/email-change/confirm/1/abc', $this->url($this->login($wl, User::PARTNER, Borrower::class)));
        // ...and a Borrower user_type on any other entity is not.
        $this->assertSame('https://admin.wl.com/email-change/confirm/1/abc', $this->url($this->login($wl, User::BORROWER, Employee::class)));
        $this->assertSame('https://admin.wl.com/email-change/confirm/1/abc', $this->url($this->login($wl, User::BORROWER, null)));
    }

    // ---- Everyone else -------------------------------------------------------

    #[Test]
    public function a_white_label_partners_logins_use_its_custom_domain_when_switched_on(): void
    {
        $wl = $this->whiteLabel();

        foreach ([[User::PARTNER, Partner::class], [User::PARTNER, Employee::class], [User::PARTNER, null]] as [$type, $entity]) {
            $this->assertSame('https://admin.wl.com/email-change/confirm/1/abc', $this->url($this->login($wl, $type, $entity)));
        }
    }

    #[Test]
    public function a_white_label_partners_logins_use_its_subdomain_without_an_active_custom_domain(): void
    {
        $this->assertSame('https://wl.fourcornerfunding.com/email-change/confirm/1/abc', $this->url($this->login($this->whiteLabel(['active_custom_url' => false]), User::PARTNER, Partner::class)));
        $this->assertSame('https://wl.fourcornerfunding.com/email-change/confirm/1/abc', $this->url($this->login($this->whiteLabel(['partner_domain' => null]), User::PARTNER, Employee::class)));
    }

    #[Test]
    public function a_certified_partners_logins_use_its_subdomain_even_with_a_custom_domain(): void
    {
        foreach ([Partner::class, Employee::class] as $entity) {
            $this->assertSame('https://cp.fourcornerfunding.com/email-change/confirm/1/abc', $this->url($this->login($this->certified(), User::PARTNER, $entity)));
        }
    }

    #[Test]
    public function a_partner_without_a_subdomain_falls_back_to_the_partner_portal(): void
    {
        $this->assertSame(self::PARTNER_PORTAL . '/email-change/confirm/1/abc', $this->url($this->login($this->certified(['partner_subdomain' => null]), User::PARTNER, Employee::class)));
        $this->assertSame(self::PARTNER_PORTAL . '/email-change/confirm/1/abc', $this->url($this->login($this->whiteLabel(['active_custom_url' => false, 'partner_subdomain' => null]), User::PARTNER, Partner::class)));
    }

    #[Test]
    public function the_hosts_own_login_and_staff_use_the_partner_portal(): void
    {
        $this->assertSame(self::PARTNER_PORTAL . '/email-change/confirm/1/abc', $this->url($this->login($this->host(), User::ADMIN, Partner::class)));
        $this->assertSame(self::PARTNER_PORTAL . '/email-change/confirm/1/abc', $this->url($this->login($this->host(), User::ADMIN, Employee::class)));
        $this->assertSame(self::PARTNER_PORTAL . '/email-change/confirm/1/abc', $this->url($this->login(null, User::ADMIN, null)));
    }

    // ---- Local ---------------------------------------------------------------

    #[Test]
    public function locally_every_link_goes_to_the_env_portals(): void
    {
        $this->app['env'] = 'local';
        config(['app.frontend_partner_url' => 'localhost:5174', 'app.frontend_borrower_url' => 'localhost:5173']);

        $this->assertSame('http://localhost:5173/email-change/confirm/1/abc', $this->url($this->borrower($this->whiteLabel())));
        $this->assertSame('http://localhost:5174/email-change/confirm/1/abc', $this->url($this->login($this->whiteLabel(), User::PARTNER, Partner::class)));
        $this->assertSame('http://localhost:5174/email-change/confirm/1/abc', $this->url($this->login($this->certified(), User::PARTNER, Employee::class)));
    }
}
