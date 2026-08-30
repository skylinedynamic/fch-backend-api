<?php

namespace App\Services\FCH\Common\Department;

use App\Models\Department\Department;

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
            ->with('employees')
            ->firstOrFail();
    }

    public function destroy($id, $partnerId)
    {
        $department = Department::where('id', $id)
            ->where('partner_id', $partnerId)
            ->firstOrFail();
        
        return $department->delete();
    }
}
