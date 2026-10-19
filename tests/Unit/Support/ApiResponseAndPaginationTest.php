<?php

namespace Tests\Unit\Support;

use App\Helpers\ApiResponse;
use App\Traits\PaginatesApi;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The response envelope every endpoint answers in and the pagination block
 * the list screens read.
 */
#[CoversClass(ApiResponse::class)]
#[CoversClass(PaginatesApi::class)]
class ApiResponseAndPaginationTest extends TestCase
{
    private function paginates(): object
    {
        return new class {
            use PaginatesApi {
                normalizePerPage as public;
                resolvePage as public;
                respondWithPagination as public;
            }
        };
    }

    #[Test]
    public function success_and_error_share_one_envelope(): void
    {
        $ok = ApiResponse::success(['id' => 1], 'Done', 201);
        $this->assertSame(201, $ok->getStatusCode());
        $this->assertSame(['status' => true, 'message' => 'Done', 'data' => ['id' => 1]], $ok->getData(true));

        $error = ApiResponse::error('Nope', 409, ['field' => ['bad']]);
        $this->assertSame(409, $error->getStatusCode());
        $this->assertSame(['status' => false, 'message' => 'Nope', 'errors' => ['field' => ['bad']]], $error->getData(true));
        $this->assertArrayNotHasKey('errors', ApiResponse::error('Nope')->getData(true));
    }

    #[Test]
    public function each_shorthand_answers_with_its_status(): void
    {
        $this->assertSame(422, ApiResponse::validationError(['a' => ['b']])->getStatusCode());
        $this->assertSame(401, ApiResponse::unauthorized()->getStatusCode());
        $this->assertSame(403, ApiResponse::forbidden()->getStatusCode());
        $this->assertSame(404, ApiResponse::notFound()->getStatusCode());
        $this->assertSame(500, ApiResponse::serverError()->getStatusCode());
        $this->assertSame(429, ApiResponse::tooManyRequests()->getStatusCode());

        $login = ApiResponse::loginError('Wrong password', 'password', 2, 5);
        $this->assertSame(401, $login->getStatusCode());
        $this->assertSame(['field' => 'password', 'remaining_attempts' => 2, 'max_attempts' => 5], array_intersect_key($login->getData(true), array_flip(['field', 'remaining_attempts', 'max_attempts'])));
    }

    #[Test]
    public function per_page_and_page_fall_back_and_are_capped(): void
    {
        $p = $this->paginates();

        $this->assertSame(15, $p->normalizePerPage(Request::create('/x')));
        $this->assertSame(25, $p->normalizePerPage(Request::create('/x', 'GET', ['per_page' => 25])));
        $this->assertSame(15, $p->normalizePerPage(Request::create('/x', 'GET', ['per_page' => 0])));
        $this->assertSame(100, $p->normalizePerPage(Request::create('/x', 'GET', ['per_page' => 5000])));

        $this->assertSame(1, $p->resolvePage(Request::create('/x')));
        $this->assertSame(3, $p->resolvePage(Request::create('/x', 'GET', ['page' => 3])));
        $this->assertSame(1, $p->resolvePage(Request::create('/x', 'GET', ['page' => -2])));
    }

    #[Test]
    public function a_page_carries_its_meta_and_links(): void
    {
        $paginator = new LengthAwarePaginator([['id' => 3], ['id' => 4]], 5, 2, 2, ['path' => 'https://api.example.org/list']);

        $body = $this->paginates()->respondWithPagination($paginator, 'Listed', 200, null, ['statistics' => ['total' => 5]])->getData(true);

        $this->assertSame([['id' => 3], ['id' => 4]], $body['data']);
        $this->assertSame(['current_page' => 2, 'from' => 3, 'to' => 4, 'per_page' => 2, 'total' => 5, 'last_page' => 3],
            array_intersect_key($body['meta'], array_flip(['current_page', 'from', 'to', 'per_page', 'total', 'last_page'])));
        $this->assertSame(['« Previous', '1', '2', '3', 'Next »'], array_column($body['meta']['links'], 'label'));
        $this->assertSame([1, 3], [$body['meta']['links'][0]['page'], $body['meta']['links'][4]['page']]);
        $this->assertTrue($body['meta']['links'][2]['active']);
        $this->assertSame(['total' => 5], $body['statistics']);
    }
}
