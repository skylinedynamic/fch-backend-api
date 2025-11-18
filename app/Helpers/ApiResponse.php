<?php

namespace App\Helpers;

use Illuminate\Http\JsonResponse;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class ApiResponse
{
    /**
     * Return a successful response.
     *
     * @param mixed $data
     * @param string $message
     * @param int $code
     * @return JsonResponse
     */
    public static function success($data = null, string $message = 'Success', int $code = 200): JsonResponse
    {
        return response()->json([
            'status'  => true,
            'message' => $message,
            'data'    => $data,
        ], $code);
    }

    /**
     * Return a successful response.
     *
     * @param LengthAwarePaginator $paginator
     * @param string $message
     * @param int $code
     * @return JsonResponse
     */

    public static function successWithPagination($data = [],LengthAwarePaginator $paginator, string $message = 'Success', int $code = 200): JsonResponse
    {
        return response()->json([
            'status' => true,
            'message' => $message,
            'data' => $data,
            // Standard pagination links
            'meta' => [
                 'current_page' => $paginator->currentPage(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
                'next_page_url' => $paginator->nextPageUrl(),
                'prev_page_url' => $paginator->previousPageUrl(),
                'links' => $paginator->linkCollection(),
            ],
        ], $code);
    }

    /**
     * Generate an error response.
     *
     * @param string $message
     * @param int $code
     * @param mixed $errors
     * @return JsonResponse
     */

    public static function error(string $message = 'Error', int $code = 400, $errors = null): JsonResponse
    {
        $response = [
            'status' => false,
            'message' => $message,
        ];

        if (!empty($errors)) {
            $response['errors'] = $errors;
        }

        return response()->json($response, $code);
    }

    /**
     * Generate a validation error response.
     *
     * @param array $errors
     * @param string $message
     * @return JsonResponse
     */

    public static function validationError($errors, string $message = 'Validation Error'): JsonResponse
    {
        return self::error($message, 422, $errors);
    }

    /**
     * Return an unauthorized response.
     *
     * @param string $message
     * @return JsonResponse
     */
    public static function unauthorized(string $message = 'Unauthorized'): JsonResponse
    {
        return self::error($message, 401);
    }

    /**
     * Return a forbidden response.
     *
     * @param string $message
     * @return JsonResponse
     */
    public static function forbidden(string $message = 'Forbidden'): JsonResponse
    {
        return self::error($message, 403);
    }

    /**
     * Return a not found response.
     *
     * @param string $message
     * @return JsonResponse
     */
    public static function notFound(string $message = 'Resource not found'): JsonResponse
    {
        return self::error($message, 404);
    }

    public static function serverError(string $message = 'Server Error'): JsonResponse
    {
        return self::error($message, 500);
    }

    /**
     * Return a too many requests response.
     *
     * @param string $message
     * @param array $rateLimitInfo
     * @return JsonResponse
     */
    public static function tooManyRequests(string $message = 'Too Many Requests'): JsonResponse
    {
        return self::error($message, 429);
    }

    public static function loginError(string $message = 'Login Error', string $field = 'email', $remainingAttempts = 0, $maxAttempts = 0): JsonResponse
    {
         $response = [
            'status' => false,
            'message' => $message,
            'field' => $field,
            'remaining_attempts' => $remainingAttempts,
            'max_attempts' => $maxAttempts,
        ];

        if (!empty($errors)) {
            $response['errors'] = $errors;
        }

        return response()->json($response, 401);
    }
}
