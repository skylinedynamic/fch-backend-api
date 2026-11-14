<?php

namespace App\Http\Controllers\Api\V1\FCH\Common\Department;

use App\Exceptions\DepartmentInUseException;
use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\FCH\Common\Department\StoreDepartmentRequest;
use App\Services\FCH\Common\Department\DepartmentService;
use Exception;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Auth;

class DepartmentController extends Controller
{
    protected $departmentService;

    public function __construct(DepartmentService $departmentService)
    {
        $this->departmentService = $departmentService;
    }

    /**
     * Department List
     */
    public function fetchDepartments()
    {
        $partnerId = Auth::user()->partner_id;
        $departments = $this->departmentService->list($partnerId);

        return ApiResponse::success($departments, 'Department list fetched successfully');
    }

    /**
     * Department Added
     */
    public function store(StoreDepartmentRequest $request)
    {
        $data = $request->only(['name']);
        $data['partner_id'] = Auth::user()->partner_id;

        $department = $this->departmentService->store($data);

        return ApiResponse::success($department, __('employee.department_created'));
    }

    /**
     * Department Details
     */
    public function show($id)
    {
        try {
            $partnerId = Auth::user()->partner_id;
            $department = $this->departmentService->show($id, $partnerId);

            return ApiResponse::success($department, __('employee.employee_details'));
        } catch (Exception $e) {
            return ApiResponse::error(__('employee.employee_not_found'));
        }
    }

    /**
     * Delete Department - refused while employees are in it (BUG-183).
     */
    public function destroy($id)
    {
        try {
            $partnerId = Auth::user()->partner_id;
            $this->departmentService->destroy($id, $partnerId);

            return ApiResponse::success([], __('employee.department_deleted'));
        } catch (DepartmentInUseException $e) {
            return ApiResponse::error($e->getMessage(), 422);
        } catch (ModelNotFoundException $e) {
            return ApiResponse::notFound(__('employee.department_not_found'));
        } catch (Exception $e) {
            return ApiResponse::error(__('employee.department_not_found'));
        }
    }
}
