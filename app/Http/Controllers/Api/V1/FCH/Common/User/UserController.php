<?php

namespace App\Http\Controllers\Api\V1\FCH\Common\User;

use Throwable;
use App\Models\User\User;
use App\Models\Common\Role;
use App\Helpers\ApiResponse;
use App\Traits\PaginatesApi;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\Controller;
use App\Http\Resources\FCH\User\UserResource;
use App\Services\FCH\Common\User\UserService;
use App\Http\Requests\Api\V1\FCH\Common\User\FetchUser;
use Illuminate\Http\Request; // ✅ this is the correct one
use App\Http\Requests\Api\V1\FCH\Common\User\StoreUserRequest;
use App\Http\Requests\Api\V1\FCH\Common\User\UpdateUserRequest;

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
     * All User
     */

    public function index()
    {
        try {
            return $this->userService->getAll();
        } catch (\Exception $e) {
            return ApiResponse::serverError(__('user.user_list_failed'));
        }
    }
     /**
     * User with Pagination
     */

    public function fetchUsers(FetchUser $request)
    {
        try {

            $partnerId = $request->user()->partner_id;
            $input = $request->validated();

            // Sanitize and cap pagination to avoid heavy payloads
            $perPage = isset($input['per_page']) ? (int) $input['per_page'] : null;
            $perPage = $perPage !== null ? max(1, min($perPage, 100)) : null;

            $filters = [
                'per_page'   => $perPage,
                'page'       => isset($input['page']) ? max(1, (int) $input['page']) : 1,
                'search'     => isset($input['search']) ? trim((string) $input['search']) : null,
                'status' => $input['status'] ?? null,
                'role_id' => $input['role'] ?? null,
                'filters'    => $input['filters'] ?? null,
                'sort_field' => $input['sort_field'] ?? null,
                'sort_order' => $input['sort_order'] ?? null,
            ];

            return $this->userService->list($partnerId, $filters);

        } catch (Throwable $e) {
            return ApiResponse::serverError(__('user.failed_to_retrieve_users'), 500);
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
                (new UserResource($user))->toArray($request), // convert resource to array
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
            new UserResource($user->load([ 'roles', 'permissions'])),
            __('user.user_retrieved')
        );
    }

    /**
     * Update User
     */
    public function update(UpdateUserRequest $request, int $id)
    {
        $roleId = Role::where('name',$request->role_id)->first()->id ?? null;
        
        $data = $request->validated();

        if (empty($data['password'] ?? null)) {
            unset($data['password']);
        }

        $user = User::findOrFail($id);
        try {
            $updated = $this->userService->update($user, $data, $roleId);
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
    public function multipleDelete(Request $request)
    {
        try {
           User::whereIn('id', $request->ids)->delete();
           return ApiResponse::success(
             [],
            __('user.user_deleted')
           );
        } catch (\Throwable $e) {
            return ApiResponse::serverError(__('user.user_delete_failed'), 500);
        }
    }
}