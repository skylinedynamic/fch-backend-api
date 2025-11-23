<?php

namespace App\Http\Controllers\Api\V1\FCH\Common\Department;

use App\Http\Controllers\Controller;
use App\Helpers\ApiResponse;
use App\Services\FCH\Common\Department\DepartmentService;
use Illuminate\Http\Request;
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

    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
        ]);

        $data = $request->only(['name']);
        $data['partner_id'] = Auth::user()->partner_id;

        $department = $this->departmentService->store($data);

        return ApiResponse::success($department, 'Department created successfully');
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
        } catch (\Exception $e) {
            return ApiResponse::error(__('employee.employee_not_found'));
        }
    }
}
