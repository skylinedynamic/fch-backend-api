<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stores OpenAI's request id alongside each stored evaluation.
 *
 * It arrives only in the response header and cannot be reconstructed afterwards,
 * and it is what OpenAI asks for when a specific generation has to be looked up
 * on their side — so an evaluation that is later queried or disputed can be
 * traced back to the exact call that produced it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pre_qualification_gpt_results', function (Blueprint $table) {
            if (!Schema::hasColumn('pre_qualification_gpt_results', 'openai_request_id')) {
                $table->string('openai_request_id')->nullable()->after('pre_qualification_request_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('pre_qualification_gpt_results', function (Blueprint $table) {
            if (Schema::hasColumn('pre_qualification_gpt_results', 'openai_request_id')) {
                $table->dropColumn('openai_request_id');
            }
        });
    }
};
