<?php

namespace App\Services\FCH\Common\Department;

use App\Exceptions\DepartmentInUseException;
use App\Models\Department\Department;
use App\Models\Employee\Employee;

class DepartmentService
{
    public function store(array $data)
    {
        return Department::create($data);
    }

    public function list($partnerId)
    {
        return Department::where('partner_id', $partnerId)->get();
    }

    public function show($id, $partnerId)
    {
        return Department::where('id', $id)
            ->where('partner_id', $partnerId)
            ->firstOrFail();
    }

    /**
     * A department still used by the partner's employees is kept (BUG-183):
     * employees store its name as text, so deleting it would leave them with a
     * department the form and the filter no longer offer.
     *
     * @throws DepartmentInUseException
     */
    public function destroy($id, $partnerId)
    {
        $department = Department::where('id', $id)
            ->where('partner_id', $partnerId)
            ->firstOrFail();

        $employees = Employee::where('partner_id', $partnerId)
            ->whereRaw('LOWER(department) = ?', [mb_strtolower($department->name)])
            ->count();

        if ($employees > 0) {
            throw new DepartmentInUseException(trans_choice('employee.department_in_use', $employees, [
                'name' => $department->name,
                'count' => $employees,
            ]));
        }

        return $department->delete();
    }
}
