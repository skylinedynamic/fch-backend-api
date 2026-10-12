<?php

namespace Tests\Feature\General;

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
 */
class DeadRoutesTest extends TestCase
{
    public static function deadRoutes(): array
    {
        return [
            'O12 delete a user' => ['DELETE', '/api/v1/common/users/1'],
            'O30 seo without an id' => ['GET', '/api/v1/admin/cms/seo'],
            'O34 update a partner document' => ['POST', '/api/v1/admin/partners/documents/1'],
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
    public function the_live_neighbours_are_still_there(): void
    {
        foreach ([
            ['GET', '/api/v1/common/users/1'],
            ['POST', '/api/v1/common/users/multiple-delete'],
            ['GET', '/api/v1/admin/cms/seo-settings/1'],
            ['GET', '/api/v1/admin/partners/documents/download/1'],
            ['POST', '/api/v1/admin/partners/documents/delete/1'],
        ] as [$method, $uri]) {
            $this->assertNotNull(Route::getRoutes()->match(Request::create($uri, $method)), "{$method} {$uri}");
        }
    }
}
