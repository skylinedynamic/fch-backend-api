<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('embedded_form_submissions', function (Blueprint $table) {
            $table->unsignedBigInteger('converted_borrower_id')
                ->nullable()
                ->after('converted_lead_id')
                ->comment('Borrower created when this submission was converted to a free trial borrower');

            $table->foreign('converted_borrower_id')
                ->references('id')
                ->on('borrowers')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('embedded_form_submissions', function (Blueprint $table) {
            $table->dropForeign(['converted_borrower_id']);
            $table->dropColumn('converted_borrower_id');
        });
    }
};
