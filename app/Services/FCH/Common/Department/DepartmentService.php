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

    /**
     * The partner's departments, each with employees_count: how many of the
     * partner's employees are in it (matched as destroy() matches them), so the
     * screen knows before asking whether one can be deleted.
     */
    public function list($partnerId)
    {
        $counts = Employee::where('partner_id', $partnerId)
            ->whereNotNull('department')
            ->selectRaw('LOWER(department) as name, COUNT(*) as total')
            ->groupByRaw('LOWER(department)')
            ->pluck('total', 'name');

        return Department::where('partner_id', $partnerId)->get()
            ->each(fn (Department $department) => $department->setAttribute(
                'employees_count',
                (int) ($counts[mb_strtolower($department->name)] ?? 0)
            ));
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
