<?php

namespace Database\Seeders;

use App\Models\Employee\Employee;
use App\Models\User\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * One-off: every user is now the login of one of its partner's employees
 * (users.entity_type = Employee, entity_id = employees.id). Users created
 * before that rule have no employee; this creates one for each, from the
 * user's own details, and links the user to it.
 *
 * Covered:
 *  - users with no entity at all (entity_type NULL);
 *  - users marked as an Employee whose employee record no longer exists.
 *
 * Left alone:
 *  - partner owner logins (entity_type Partner) and borrowers - not employees;
 *  - Developer accounts - hidden from partners' screens, so they get no
 *    employee record a partner could see;
 *  - users with no partner - an employee always belongs to one.
 *
 * Safe to run more than once: a linked user no longer matches.
 *
 *   php artisan db:seed --class=LinkUsersToEmployeesSeeder
 */
class LinkUsersToEmployeesSeeder extends Seeder
{
    public function run(): void
    {
        $linked = 0;

        $this->usersWithoutEmployee()->orderBy('id')->each(function (User $user) use (&$linked) {
            DB::transaction(function () use ($user) {
                $employee = Employee::create([
                    'partner_id' => $user->partner_id,
                    // first_name holds the employee's whole name.
                    'first_name' => $user->name,
                    'email' => $user->email,
                    'phone_number_1' => $user->phone_number,
                    'job_status' => $user->status === User::STATUS['active']
                        ? Employee::STATUS_ACTIVE
                        : Employee::STATUS_INACTIVE,
                    'joining_date' => $user->created_at?->toDateString(),
                    'employee_code' => Employee::generateCode($user->partner_id),
                ]);

                $user->forceFill([
                    'entity_type' => Employee::class,
                    'entity_id' => $employee->id,
                ])->saveQuietly();
            });

            $linked++;
        });

        $this->command?->info("Linked {$linked} user(s) to a new employee record.");
    }

    private function usersWithoutEmployee(): Builder
    {
        return User::query()
            ->whereNotNull('partner_id')
            ->where(function (Builder $query) {
                $query->whereNull('entity_type')
                    ->orWhere(function (Builder $query) {
                        $query->where('entity_type', Employee::class)
                            ->whereDoesntHave('employee');
                    });
            })
            ->whereDoesntHave('roles', fn (Builder $query) => $query->where('code', 'developer'));
    }
}
