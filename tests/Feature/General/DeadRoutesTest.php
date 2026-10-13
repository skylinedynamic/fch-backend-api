<?php

namespace Tests\Feature\General;

use App\Http\Controllers\Api\V1\FCH\Common\RolePermission\RoleController;
use App\Http\Controllers\Api\V1\FCH\Common\RolePermission\UserRoleController;
use App\Services\FCH\Common\RolePermission\RoleService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

/**
 * Routes that pointed at controller methods that do not exist or cannot work,
 * so anything reaching them got a 500. None of the portals call them.
 *
 *   O12  DELETE /common/users/{user}   - apiResource registered destroy(); UserController has none
 *                                        (deleting goes through users/multiple-delete)
 *   O30  GET    /admin/cms/seo         - SeoController::show($id) with no {id} in the route
 *   O34  POST   /admin/partners/documents/{id} - PartnerDocumentController::update is commented out
 *   O19  DELETE /common/leads/{id}     - one lead or many, leads are deleted through
 *                                        POST /common/leads/multiple-delete
 *
 * And code no route reached at all:
 *
 *   O10  RoleController/RoleService::assignPermissions() + AssignPermissionsRequest - filtered
 *        permissions by a partner_id column the permissions table does not have, so it could
 *        only throw; roles get their permissions through store/update instead
 */
class DeadRoutesTest extends TestCase
{
    public static function deadRoutes(): array
    {
        return [
            'O12 delete a user' => ['DELETE', '/api/v1/common/users/1'],
            'O30 seo without an id' => ['GET', '/api/v1/admin/cms/seo'],
            'O34 update a partner document' => ['POST', '/api/v1/admin/partners/documents/1'],
            'O19 single lead delete' => ['DELETE', '/api/v1/common/leads/1'],
            'O53 borrower self sign-up' => ['POST', '/api/v1/borrower/register'],
        ];
    }

    #[Test]
    #[DataProvider('deadRoutes')]
    public function the_route_is_not_registered(string $method, string $uri): void
    {
        try {
            Route::getRoutes()->match(Request::create($uri, $method));
            $this->fail("{$method} {$uri} is still registered");
        } catch (NotFoundHttpException|MethodNotAllowedHttpException) {
            $this->assertTrue(true);
        }
    }

    #[Test]
    public function the_unreachable_role_permission_assignment_is_gone(): void
    {
        $this->assertFalse(method_exists(RoleController::class, 'assignPermissions'));
        $this->assertFalse(method_exists(RoleService::class, 'assignPermissions'));
        $this->assertFalse(class_exists('App\Http\Requests\Api\V1\FCH\Common\RolePermission\AssignPermissionsRequest'));

        // The user-level assignment is a different, routed method and stays.
        $this->assertTrue(method_exists(UserRoleController::class, 'assignPermissions'));
        $this->assertNotNull(Route::getRoutes()->match(Request::create('/api/v1/common/users/1/permissions', 'POST')));
    }

    /**
     * O37: require-2fa and check-2fa-status were registered but on no route.
     * check-2fa-status read session('2fa_verified'), which a token API never
     * sets, so on any route it would have refused every 2FA user. 2FA is not
     * live; a rebuild belongs with the token-based 2FA work (D3).
     */
    #[Test]
    public function the_unused_2fa_middleware_is_gone(): void
    {
        $aliases = app('router')->getMiddleware();

        $this->assertArrayNotHasKey('require-2fa', $aliases);
        $this->assertArrayNotHasKey('check-2fa-status', $aliases);
        $this->assertFalse(class_exists('App\Http\Middleware\RequireTwoFactor'));
        $this->assertFalse(class_exists('App\Http\Middleware\CheckTwoFactorStatus'));

        // The aliases routes do use are untouched.
        foreach (['partner', 'borrower', 'admin', 'partner-portal', 'host-only', 'permission'] as $alias) {
            $this->assertArrayHasKey($alias, $aliases);
        }
    }

    /**
     * O46: GlobalPaymentNotificationMail ("New Payment Received") was never
     * sent - the success/submitted mails do that job. Its sibling
     * PaymentFailedMail is now sent by the auto-charge cron instead of removed.
     */
    #[Test]
    public function the_unsent_payment_notification_mail_is_gone(): void
    {
        $this->assertFalse(class_exists('App\Mail\Payment\GlobalPaymentNotificationMail'));
        $this->assertFalse(view()->exists('emails.payment.global_notification'));
        $this->assertTrue(class_exists('App\Mail\Payment\PaymentFailedMail'));
    }

    /**
     * O51: StripeWebhookService had no route and was never called. Had it been
     * routed it checked no Stripe signature (anyone could post "payment
     * succeeded") and redid what the app already does when it charges. No
     * webhook feature is planned for now; one would be built fresh.
     */
    #[Test]
    public function the_unrouted_stripe_webhook_service_is_gone(): void
    {
        $this->assertFalse(class_exists('App\Services\GlobalPayment\Stripe\StripeWebhookService'));
        $this->assertTrue(class_exists('App\Services\GlobalPayment\Stripe\StripeCustomerService'));
    }

    /**
     * O53: classes nothing reached, removed - and borrower self sign-up, which
     * was switched off in both the API (route commented out) and the borrower
     * portal, removed as a feature (decision: borrowers do not sign up
     * themselves).
     */
    #[Test]
    public function the_unreachable_services_and_borrower_self_signup_are_gone(): void
    {
        foreach ([
            'App\Services\Global\Verification\BusinessVerificationService',
            'App\Services\Global\OpenCorporates\OpenCorporatesService',
            'App\Services\FCH\Common\RolePermission\UserPermissionService',
            'App\Services\FCH\Admin\Configuration\AppConfigurationService',
            'App\Services\Global\Notification\Types\AccountCreatedNotification',
            'App\Services\Borrower\Resource\ResourceService',
            'App\Http\Controllers\Api\V1\FCH\Admin\FundingApplication\PartnerAssistedApplicationController',
            'App\Services\FCH\Admin\FundingApplication\PartnerAssistedApplicationService',
            'App\Services\Borrower\Auth\BorrowerAuthService',
            'App\Http\Requests\Api\V1\Borrower\Auth\BorrowerRegistrationRequest',
            'App\Models\Borrower\BorrowerSignupId',
        ] as $class) {
            $this->assertFalse(class_exists($class), "{$class} is still there");
        }

        $this->assertFalse(method_exists(\App\Http\Controllers\Api\V1\Borrower\Auth\AuthController::class, 'register'));
        $this->assertNull(config('services.opencorporates'));

        // The live twins stay.
        $this->assertTrue(class_exists('App\Services\FCH\Common\Setting\AppConfigurationService'));
        $this->assertTrue(class_exists('App\Services\FCH\Admin\Resource\ResourceService'));
        $this->assertTrue(method_exists(\App\Http\Controllers\Api\V1\Borrower\Auth\AuthController::class, 'login'));
    }

    /** Listed in O53 by mistake: it is the server's ops dashboard, routed in routes/web.php. */
    #[Test]
    public function the_ops_dashboard_is_live_and_stays(): void
    {
        $this->assertTrue(class_exists('App\Http\Controllers\Web\DashboardController'));
        $this->assertNotNull(Route::getRoutes()->match(Request::create('/dashboard', 'GET')));
    }

    #[Test]
    public function the_live_neighbours_are_still_there(): void
    {
        foreach ([
            ['GET', '/api/v1/common/users/1'],
            ['GET', '/api/v1/common/leads/1'],
            ['POST', '/api/v1/common/leads/multiple-delete'],
            ['POST', '/api/v1/common/users/multiple-delete'],
            ['GET', '/api/v1/admin/cms/seo-settings/1'],
            ['GET', '/api/v1/admin/partners/documents/download/1'],
            ['POST', '/api/v1/admin/partners/documents/delete/1'],
        ] as [$method, $uri]) {
            $this->assertNotNull(Route::getRoutes()->match(Request::create($uri, $method)), "{$method} {$uri}");
        }
    }
}
