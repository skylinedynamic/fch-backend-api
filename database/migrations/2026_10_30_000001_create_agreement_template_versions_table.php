<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An agreement template was overwritten on every save, so once a partner
 * edited it nobody could tell which wording an earlier borrower had agreed to.
 * Each change of wording is now kept as a version that never changes, and a
 * signature records the version signed. Signatures made before this have no
 * version (null); their own content_html snapshot remains their record.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agreement_template_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agreement_template_id')->constrained('agreement_templates')->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->longText('template_html')->nullable();
            $table->longText('template_json')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->unique(['agreement_template_id', 'version']);
        });

        Schema::table('agreement_signs', function (Blueprint $table) {
            $table->foreignId('agreement_template_version_id')->nullable()->after('agreement_template_id')
                ->constrained('agreement_template_versions')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('agreement_signs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('agreement_template_version_id');
        });

        Schema::dropIfExists('agreement_template_versions');
    }
};
