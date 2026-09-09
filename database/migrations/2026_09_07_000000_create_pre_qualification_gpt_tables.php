<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * GPT evaluation storage for pre-qualification.
 *
 * Adds two groups of columns to pre_qualification_requests:
 *   validation_* — outcome of the data integrity validator that runs first
 *   gpt_*        — status and timestamp of the underwriting evaluation itself
 *
 * Both halves of an evaluation live in ONE results table, distinguished by "type":
 *   internal — underwriting memo, risk flags, lender routing (FCH staff only)
 *   client   — borrower-safe summary, programs, document checklist
 *
 * "run_id" pairs the two rows produced by a single evaluation, so repeat runs
 * can be told apart rather than matched by timestamp.
 *
 * Reads MUST be scoped by type. Use App\Models\Borrower\PreQualificationGptResult
 * with its client() / internal() scopes — an unscoped read on a borrower-facing
 * endpoint would expose the internal payload.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pre_qualification_requests', function (Blueprint $table) {
            // Data integrity validator outcome, recorded before any underwriting.
            if (!Schema::hasColumn('pre_qualification_requests', 'validation_status')) {
                $table->string('validation_status')->nullable()->after('consents');
            }
            if (!Schema::hasColumn('pre_qualification_requests', 'validation_version')) {
                $table->string('validation_version')->nullable()->after('validation_status');
            }
            if (!Schema::hasColumn('pre_qualification_requests', 'validation_summary')) {
                $table->text('validation_summary')->nullable()->after('validation_version');
            }
            if (!Schema::hasColumn('pre_qualification_requests', 'validation_issues')) {
                $table->json('validation_issues')->nullable()->after('validation_summary');
            }
            if (!Schema::hasColumn('pre_qualification_requests', 'validated_at')) {
                $table->timestamp('validated_at')->nullable()->after('validation_issues');
            }

            // PENDING | COMPLETED | BLOCKED | FAILED
            if (!Schema::hasColumn('pre_qualification_requests', 'gpt_status')) {
                $table->string('gpt_status')->nullable()->after('validated_at');
            }
            if (!Schema::hasColumn('pre_qualification_requests', 'gpt_generated_at')) {
                $table->timestamp('gpt_generated_at')->nullable()->after('gpt_status');
            }
        });

        if (!Schema::hasTable('pre_qualification_gpt_results')) {
            Schema::create('pre_qualification_gpt_results', function (Blueprint $table) {
                $table->id();
                // Constraints are named explicitly: the auto-generated names
                // (table + column + "_foreign") exceed MySQL's 64-character
                // identifier limit for these column names.
                $table->foreignId('pre_qualification_request_id')
                    ->constrained('pre_qualification_requests', indexName: 'pq_gpt_request_fk')
                    ->cascadeOnDelete();
                $table->string('type', 20);                     // internal | client
                $table->uuid('run_id')->nullable();             // pairs one evaluation's two rows
                $table->string('prompt_version');
                $table->string('matrix_version')->nullable();   // internal only
                $table->string('model')->nullable();
                $table->string('reasoning_effort')->nullable(); // internal only
                $table->json('payload');
                $table->boolean('is_placeholder')->default(false);
                $table->unsignedInteger('latency_ms')->nullable();
                $table->foreignId('created_by')->nullable()
                    ->constrained('users', indexName: 'pq_gpt_created_by_fk')
                    ->nullOnDelete();
                $table->timestamps();

                $table->index(['pre_qualification_request_id', 'type'], 'pq_gpt_request_type_idx');
                $table->index('run_id', 'pq_gpt_run_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('pre_qualification_gpt_results');

        Schema::table('pre_qualification_requests', function (Blueprint $table) {
            $columns = [
                'validation_status',
                'validation_version',
                'validation_summary',
                'validation_issues',
                'validated_at',
                'gpt_status',
                'gpt_generated_at',
            ];

            foreach ($columns as $column) {
                if (Schema::hasColumn('pre_qualification_requests', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
