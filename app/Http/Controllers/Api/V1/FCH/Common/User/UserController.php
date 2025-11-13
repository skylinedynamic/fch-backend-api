<?php

namespace App\Http\Controllers\Api\V1\FCH\Common\User;

use Throwable;
use App\Models\User\User;
use App\Helpers\ApiResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request; // ✅ this is the correct one
use App\Http\Resources\FCH\User\UserResource;
use App\Services\FCH\Common\User\UserService;
use App\Http\Requests\Api\V1\FCH\Common\User\StoreUserRequest;
use App\Http\Requests\Api\V1\FCH\Common\User\UpdateUserRequest;
use App\Traits\PaginatesApi;

class UserController extends Controller
{

   
    /**
     * PermissionController constructor.
     *
     * @param UserService $userService
     */
    use PaginatesApi;
    public function __construct(protected UserService $userService)
    {
    }

     /**
     * User List
     */

    public function index(Request $request)
    {
        try {
            // Paginate users via service (per_page comes from request or defaults to 10)
            $users = $this->userService->list($request);

            // Respond using pagination resource
            return $this->respondWithPaginationResource(
                $request,
                $users,
                UserResource::class,
                __('user.user_list_retrieved')
            );

        } catch (Throwable $e) {
            return ApiResponse::error($e->getMessage(), 400);
        }
    }

    /**
     * Create User
     */
    public function store(StoreUserRequest $request)
    {
        try {
            $user = $this->userService->create($request->validated(), $request->role_id);
            return ApiResponse::success(
                new UserResource($user),
                __('user.user_created')
            );
        } catch (Throwable $e) {
            return ApiResponse::error(__($e->getMessage()), 400);
        }
    }

    /**
     * Show User
     */
    public function show(User $user)
    {
        return ApiResponse::success(
            new UserResource($user->load(['employee', 'roles', 'permissions'])),
            __('user.user_retrieved')
        );
    }

    /**
     * Update User
     */
    public function update(UpdateUserRequest $request, int $id)
    {
        $user = User::findOrFail($id);
        try {
            $updated = $this->userService->update($user, $request->validated(), $request->role_id);
            return ApiResponse::success(
                new UserResource($updated),
                __('user.user_updated')
            );
        } catch (Throwable $e) {
            return ApiResponse::error(__($e->getMessage()), 400);
        }
    }
    /**
     * Delete User
     */
    public function destroy(Request $request, int $id)
    {
          
        $user = User::with('employee')->find($id);

        try {
            $this->userService->delete($user, true);

            return response()->json([
                'status' => true,
                'message' => __('user.user_deleted'),
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'status' => false,
                'message' => __('user.user_delete_failed'),
                'error' => $e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
