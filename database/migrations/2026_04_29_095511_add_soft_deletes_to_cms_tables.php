<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('blog_categories', function (Blueprint $table) {
            if (!Schema::hasColumn('blog_categories', 'deleted_at')) {
                $table->softDeletes();
            }
        });

        Schema::table('blog_tags', function (Blueprint $table) {
            if (!Schema::hasColumn('blog_tags', 'deleted_at')) {
                $table->softDeletes();
            }
        });

        Schema::table('seo_settings', function (Blueprint $table) {
            if (!Schema::hasColumn('seo_settings', 'deleted_at')) {
                $table->softDeletes();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('seo_settings', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });

        Schema::table('blog_tags', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });

        Schema::table('blog_categories', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};
