<?php

namespace Tests\Feature\Auth;

use App\Http\Middleware\RejectBorrowersFromPartnerPortal;
use App\Models\Partner\Partner;
use App\Models\User\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/**
 * /api/v1/common/* serves the partner portal. A borrower's token is a valid
 * api token too, so without a check of its own every partner screen - users,
 * employees, leads, roles, settings - answered a borrower as well. Borrowers
 * now reach only the /common routes the borrower portal itself calls.
 */
#[CoversClass(RejectBorrowersFromPartnerPortal::class)]
class BorrowerCommonRouteAccessTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithPortalUsers;

    private Partner $partner;
    private User $borrowerUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->makeHostPartner();
        $this->partner = $this->makeCertifiedPartner();
        $this->borrowerUser = $this->makeBorrowerUser($this->partner);
    }

    /** A sample of partner-portal screens a borrower has no business in. */
    public static function partnerOnlyRoutes(): array
    {
        return [
            'dashboard' => ['GET', '/api/v1/common/dashboard'],
            'users' => ['POST', '/api/v1/common/users/fetch-users'],
            'user list' => ['GET', '/api/v1/common/users'],
            'employees' => ['GET', '/api/v1/common/employees'],
            'create employee' => ['POST', '/api/v1/common/employees'],
            'roles' => ['GET', '/api/v1/common/roles'],
            'permissions' => ['POST', '/api/v1/common/permissions'],
            'borrower list' => ['POST', '/api/v1/common/borrowers/fetch-all-borrowers'],
            'assignable users' => ['POST', '/api/v1/common/borrowers-get-all-users'],
            'leads' => ['POST', '/api/v1/common/leads/fetch-leads'],
            'lead bulk delete' => ['POST', '/api/v1/common/leads/multiple-delete'],
            'invoices' => ['POST', '/api/v1/common/invoices/fetch-invoices'],
            'reminders' => ['POST', '/api/v1/common/reminders/fetch-reminders'],
        ];
    }

    /** Every /common route the borrower portal (borrower-fch) calls. */
    public static function borrowerPortalRoutes(): array
    {
        return [
            'appointments list' => ['POST', '/api/v1/common/appointments/fetch-all'],
            'appointment' => ['GET', '/api/v1/common/appointments/999999'],
            'book appointment' => ['POST', '/api/v1/common/appointments'],
            'move appointment' => ['PUT', '/api/v1/common/appointments/999999'],
            'cancel appointment' => ['DELETE', '/api/v1/common/appointments/999999'],
            'availability' => ['POST', '/api/v1/common/appointments/check-availability'],
            'bookable people' => ['POST', '/api/v1/common/appointments/fetch-selectable-users'],
            'refresh user' => ['GET', '/api/v1/common/refresh-user'],
            'payment providers' => ['GET', '/api/v1/common/partner-payment-providers'],
            'document download' => ['GET', '/api/v1/common/borrowers-documents/download/999999'],
            'readiness factors' => ['GET', '/api/v1/common/funding-qualification/999999/readiness-factors'],
            'send invoice' => ['POST', '/api/v1/common/invoices/999999/send'],
            'application' => ['GET', '/api/v1/common/request-application/funding-application/999999'],
            'notifications' => ['GET', '/api/v1/common/notifications'],
            'unread count' => ['GET', '/api/v1/common/notifications/unread-count'],
            'read all' => ['POST', '/api/v1/common/notifications/read-all'],
            'read one' => ['POST', '/api/v1/common/notifications/999999/read'],
            'delete one' => ['DELETE', '/api/v1/common/notifications/999999'],
            'approve payment' => ['POST', '/api/v1/common/payments/999999/approve'],
            'reject payment' => ['POST', '/api/v1/common/payments/999999/reject'],
        ];
    }

    #[Test]
    #[DataProvider('partnerOnlyRoutes')]
    public function a_borrower_is_turned_away_from_partner_screens(string $method, string $url): void
    {
        $this->actingAsUser($this->borrowerUser);

        $this->json($method, $url)->assertForbidden()->assertJsonPath('status', false);
    }

    /**
     * The mail settings are behind their own permission, so they are checked
     * apart from the routes any of the partner's team can open.
     */
    #[Test]
    public function the_mail_settings_turn_a_borrower_away_and_open_for_the_partner(): void
    {
        $this->actingAsUser($this->borrowerUser);
        $this->getJson('/api/v1/common/mail-configurations')->assertForbidden()->assertJsonPath('status', false);

        $this->actingAsUser($this->makePartnerAdmin($this->partner, ['update email configuration settings']));
        $this->getJson('/api/v1/common/mail-configurations')->assertOk();
    }

    #[Test]
    #[DataProvider('partnerOnlyRoutes')]
    public function the_partners_own_team_still_reaches_them(string $method, string $url): void
    {
        $this->actingAsUser($this->makePartnerAdmin($this->partner));

        $this->assertNotSame(403, $this->json($method, $url)->status());
    }

    #[Test]
    #[DataProvider('borrowerPortalRoutes')]
    public function a_borrower_still_reaches_what_the_borrower_portal_uses(string $method, string $url): void
    {
        $this->actingAsUser($this->borrowerUser);

        $response = $this->json($method, $url);

        // Whatever the action makes of the request, the gate let it through.
        $this->assertNotSame(
            __('auth.access_denied_partner_portal'),
            $response->json('message'),
            "{$method} {$url} was turned away"
        );
    }
}
