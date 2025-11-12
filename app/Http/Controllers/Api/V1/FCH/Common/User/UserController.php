<?php

namespace App\Http\Controllers\Api\V1\FCH\Common\User;

use Throwable;
use App\Models\User\User;
use App\Helpers\ApiResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Request;
use App\Http\Resources\FCH\User\UserResource;
use App\Services\FCH\Common\User\UserService;
use App\Http\Requests\Api\V1\FCH\Common\User\StoreUserRequest;
use App\Http\Requests\Api\V1\FCH\Common\User\UpdateUserRequest;

class UserController extends Controller
{

   
    /**
     * PermissionController constructor.
     *
     * @param UserService $userService
     */
    public function __construct(protected UserService $userService)
    {
    }

    /**
     * List all users
     */
    public function index()
    {
        try {
            $users = $this->userService->list(request()->all());
            return ApiResponse::success(
                UserResource::collection($users),
                __('user.user_list_retrieved')
            );
        } catch (Throwable $e) {
            return ApiResponse::error(__($e->getMessage()), 400);
        }
    }

    /**
     * Create user
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
     * Show single user
     */
    public function show(User $user)
    {
        return ApiResponse::success(
            new UserResource($user->load(['employee', 'roles', 'permissions'])),
            __('user.user_retrieved')
        );
    }

    /**
     * Update user
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
