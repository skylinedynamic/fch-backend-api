<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Middle name on the client and lending forms.
 *
 * Optional everywhere: nullable, no default, nothing to backfill. First and last
 * name remain the only required parts of a person's name.
 *
 * credit_profile_requests already had middle_name, which is why it is absent from
 * this list - the column shape here matches it deliberately.
 *
 * Deliberately NOT added to contacts, employees or pre_qualification_requests:
 * those are not client or lending forms, and widening the change would make it
 * harder to verify what actually needs to display a middle name.
 */
return new class extends Migration
{
    /** table => the column middle_name should sit after. */
    private array $tables = [
        'borrowers' => 'first_name',
        'funding_applications' => 'first_name',
        'funding_application_co_borrowers' => 'first_name',
        'embedded_form_submissions' => 'first_name',
        'embedded_form_co_borrowers' => 'first_name',
    ];

    public function up(): void
    {
        foreach ($this->tables as $table => $after) {
            if (!Schema::hasTable($table) || Schema::hasColumn($table, 'middle_name')) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) use ($table, $after) {
                $column = $blueprint->string('middle_name')->nullable();

                if (Schema::hasColumn($table, $after)) {
                    $column->after($after);
                }
            });
        }
    }

    public function down(): void
    {
        foreach (array_keys($this->tables) as $table) {
            if (!Schema::hasTable($table) || !Schema::hasColumn($table, 'middle_name')) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->dropColumn('middle_name');
            });
        }
    }
};
