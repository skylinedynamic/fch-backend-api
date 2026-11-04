<?php

use App\Models\Employee\Employee;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Employees saved with job status "[object Object]" - the portal sent an
 * object instead of the value (R2 BUG-029; EMP-00002 on stage). The list
 * showed it as is and the Status filter never matched it. The API now takes
 * only Active, Inactive or Suspended; rows already stored that way are set to
 * Active, the status an employee is created with when none is given.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('employees')
            ->where('job_status', '[object Object]')
            ->update(['job_status' => Employee::STATUS_ACTIVE]);
    }

    /** The unreadable value is not worth restoring. */
    public function down(): void
    {
    }
};
