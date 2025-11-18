<?php

namespace App\Traits;

use App\Helpers\ApiResponse;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

trait PaginatesApi
{
    protected function normalizePerPage(Request $request, int $default = 15, int $max = 100): int
    {
        $perPage = (int) ($request->input('per_page', $request->query('per_page', $default)));
        if ($perPage <= 0) {
            $perPage = $default;
        }
        return min($perPage, $max);
    }

    protected function resolvePage(Request $request): int
    {
        $page = (int) ($request->input('page', $request->query('page', 1)));
        return $page > 0 ? $page : 1;
    }

    protected function paginateBuilder(Request $request, EloquentBuilder|QueryBuilder $query): LengthAwarePaginator
    {
        $perPage = $this->normalizePerPage($request);
        $page = $this->resolvePage($request);
        return $query->paginate($perPage, ['*'], 'page', $page);
    }

    protected function mapPaginatorItems(LengthAwarePaginator $paginator, callable $mapper, ?Request $request = null): LengthAwarePaginator
    {
        $items = $paginator->items();
        $transformed = [];

        foreach ($items as $item) {
            $transformed[] = $mapper($item, $request);
        }

        return new LengthAwarePaginator(
            $transformed,
            $paginator->total(),
            $paginator->perPage(),
            $paginator->currentPage(),
            [
                'path' => $request ? $request->url() : request()->url(),
                'query' => $request ? $request->query() : request()->query(),
            ]
        );
    }

    protected function respondWithPagination(LengthAwarePaginator $paginator, string $message = 'Success', int $code = 200)
    {
        $meta = [
            'current_page' => $paginator->currentPage(),
            'from' => $paginator->firstItem(),
            'to' => $paginator->lastItem(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
            'last_page' => $paginator->lastPage(),
            'next_page_url' => $paginator->nextPageUrl(),
            'prev_page_url' => $paginator->previousPageUrl(),
            'links' => $this->buildLinks($paginator),
        ];

        return ApiResponse::success()

        return response()->json([
            'status' => true,
            'message' => $message,
            'data' => $paginator->items(),
            'meta' => $meta,
        ], $code);
    }

    protected function respondWithPaginationResource(Request $request, LengthAwarePaginator $paginator, string $resourceClass, string $message = 'Success', int $code = 200)
    {
        $mapped = $this->mapPaginatorItems(
            $paginator,
            function ($item, $req) use ($resourceClass) {
                return (new $resourceClass($item))->toArray($req ?? request());
            },
            $request
        );

        return $this->respondWithPagination($mapped, $message, $code);
    }

    protected function buildLinks(LengthAwarePaginator $paginator): array
    {
        $links = [];

        $links[] = [
            'url' => $paginator->previousPageUrl(),
            'label' => '« Previous',
            'page' => $paginator->currentPage() > 1 ? $paginator->currentPage() - 1 : null,
            'active' => false,
        ];

        for ($i = 1; $i <= $paginator->lastPage(); $i++) {
            $links[] = [
                'url' => $paginator->url($i),
                'label' => (string) $i,
                'page' => $i,
                'active' => $i === $paginator->currentPage(),
            ];
        }

        $links[] = [
            'url' => $paginator->nextPageUrl(),
            'label' => 'Next »',
            'page' => $paginator->currentPage() < $paginator->lastPage() ? $paginator->currentPage() + 1 : null,
            'active' => false,
        ];

        return $links;
    }
}