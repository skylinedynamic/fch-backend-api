<?php

namespace Tests\Feature\Auth;

use App\Http\Middleware\EnsureUserHasPartner;
use App\Models\Partner\Partner;
use App\Models\User\User;
use App\Services\Global\Auth\AuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/**
 * Every user belongs to a partner. One whose partner cannot be found - no
 * partner_id, or the partner has been deleted - may not sign in and may not
 * use any authenticated route.
 *
 * Defect (findings O6): the invoice and payment scopes treated a user with no
 * partner as the host, so such a user saw every partner's invoices and
 * payments - and, with no host partner on record, every invoice there was.
 */
#[CoversClass(EnsureUserHasPartner::class)]
#[CoversClass(AuthService::class)]
class UserWithoutPartnerTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithPortalUsers;

    private const PARTNER_PORTAL = 'partner.fch.test';
    private const BORROWER_PORTAL = 'app.fch.test';

    private Partner $host;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'domains.reserved.base_reserved_partner_portal' => self::PARTNER_PORTAL,
            'domains.reserved.base_reserved_borrower_portal' => self::BORROWER_PORTAL,
        ]);

        $this->createPersonalAccessClient();
        $this->host = $this->makeHostPartner();
    }

    private function orphanAdmin(): User
    {
        return $this->makeUser(['user_type' => User::ADMIN, 'partner_id' => null]);
    }

    /** A partner's own login whose partner has since been deleted. */
    private function adminOfDeletedPartner(): User
    {
        $partner = $this->makeCertifiedPartner();
        $user = $this->makePartnerAdmin($partner);
        $partner->delete();

        return $user->fresh();
    }

    #[Test]
    public function a_user_with_no_partner_cannot_sign_in(): void
    {
        $user = $this->orphanAdmin();

        $this->withHeader('Origin', 'https://' . self::PARTNER_PORTAL)
            ->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password'])
            ->assertUnauthorized()
            ->assertJsonPath('message', __('auth.no_partner'))
            ->assertJsonMissingPath('data.token');
    }

    #[Test]
    public function a_user_whose_partner_was_deleted_cannot_sign_in(): void
    {
        $user = $this->adminOfDeletedPartner();

        $this->withHeader('Origin', 'https://' . self::PARTNER_PORTAL)
            ->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password'])
            ->assertUnauthorized()
            ->assertJsonPath('message', __('auth.no_partner'))
            ->assertJsonMissingPath('data.token');
    }

    #[Test]
    public function a_borrower_with_no_partner_cannot_sign_in(): void
    {
        $user = $this->makeBorrowerUser($this->host, userAttributes: ['partner_id' => null]);

        $this->withHeader('Origin', 'https://' . self::BORROWER_PORTAL)
            ->postJson('/api/v1/borrower/login', ['email' => $user->email, 'password' => 'password'])
            ->assertUnauthorized()
            ->assertJsonPath('message', __('auth.no_partner'))
            ->assertJsonMissingPath('data.token');
    }

    #[Test]
    public function a_signed_in_user_with_no_partner_is_refused_on_every_route(): void
    {
        $this->actingAsUser($this->orphanAdmin());

        $this->postJson('/api/v1/common/invoices/fetch-invoices')
            ->assertForbidden()
            ->assertJsonPath('message', __('auth.no_partner'));
        $this->postJson('/api/v1/common/payments/fetch-payments')->assertForbidden();
        $this->postJson('/api/v1/auth/logout')->assertForbidden();
    }

    #[Test]
    public function a_signed_in_user_whose_partner_was_deleted_is_refused(): void
    {
        $this->actingAsUser($this->adminOfDeletedPartner());

        $this->postJson('/api/v1/common/invoices/fetch-invoices')
            ->assertForbidden()
            ->assertJsonPath('message', __('auth.no_partner'));
    }

    #[Test]
    public function a_signed_in_borrower_with_no_partner_is_refused(): void
    {
        $this->actingAsUser($this->makeBorrowerUser($this->host, userAttributes: ['partner_id' => null]));

        $this->getJson('/api/v1/borrower/borrower-theme')
            ->assertForbidden()
            ->assertJsonPath('message', __('auth.no_partner'));
    }

    #[Test]
    public function a_user_with_a_partner_is_let_through(): void
    {
        $this->actingAsUser($this->makePartnerAdmin($this->host));

        $this->postJson('/api/v1/common/invoices/fetch-invoices')->assertOk();
    }

    #[Test]
    public function public_routes_are_not_affected(): void
    {
        $this->getJson('/api/v1/common/locations')->assertOk();
    }
}
