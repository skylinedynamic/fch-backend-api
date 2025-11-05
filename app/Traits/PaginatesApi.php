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
    /**
     * Normalize per_page from request with defaults and max cap.
     */
    protected function normalizePerPage(Request $request, int $default = 15, int $max = 100): int
    {
        $perPage = (int) $request->query('per_page', $default);
        if ($perPage <= 0) {
            $perPage = $default;
        }
        return min($perPage, $max);
    }

    /**
     * Paginate an Eloquent or Query builder using normalized per_page.
     */
    protected function paginateBuilder(Request $request, EloquentBuilder|QueryBuilder $query): LengthAwarePaginator
    {
        $perPage = $this->normalizePerPage($request);
        return $query->paginate($perPage);
    }

    /**
     * Map paginator items through a transformer to produce a new paginator.
     * Useful when you want to apply a Resource transformation before responding.
     */
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

    /**
     * Respond with standardized pagination using ApiResponse helper.
     */
    protected function respondWithPagination(LengthAwarePaginator $paginator, string $message = 'Success', int $code = 200)
    {
        return ApiResponse::successWithPagination($paginator, $message, $code);
    }

    /**
     * Respond with standardized pagination, applying a Resource class to items.
     */
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
}