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
        // Add columns to blog_tags
        Schema::table('blog_tags', function (Blueprint $table) {
            if (!Schema::hasColumn('blog_tags', 'partner_id')) {
                $table->foreignId('partner_id')->nullable()->after('id')->constrained('partners')->onDelete('cascade');
            }
            if (!Schema::hasColumn('blog_tags', 'created_by')) {
                $table->foreignId('created_by')->nullable()->constrained('users')->onDelete('set null');
            }
            if (!Schema::hasColumn('blog_tags', 'updated_by')) {
                $table->foreignId('updated_by')->nullable()->constrained('users')->onDelete('set null');
            }
        });

        // Add columns to blog_categories
        Schema::table('blog_categories', function (Blueprint $table) {
            if (!Schema::hasColumn('blog_categories', 'partner_id')) {
                $table->foreignId('partner_id')->nullable()->after('id')->constrained('partners')->onDelete('cascade');
            }
            if (!Schema::hasColumn('blog_categories', 'created_by')) {
                $table->foreignId('created_by')->nullable()->constrained('users')->onDelete('set null');
            }
            if (!Schema::hasColumn('blog_categories', 'updated_by')) {
                $table->foreignId('updated_by')->nullable()->constrained('users')->onDelete('set null');
            }
        });

        // Add columns to blogs
        Schema::table('blogs', function (Blueprint $table) {
            if (!Schema::hasColumn('blogs', 'partner_id')) {
                $table->foreignId('partner_id')->nullable()->after('id')->constrained('partners')->onDelete('cascade');
            }
            if (!Schema::hasColumn('blogs', 'blog_tag_id')) {
                $table->foreignId('blog_tag_id')->nullable()->after('category_id')->constrained('blog_tags')->onDelete('set null');
            }
            if (!Schema::hasColumn('blogs', 'created_by')) {
                $table->foreignId('created_by')->nullable()->constrained('users')->onDelete('set null');
            }
            if (!Schema::hasColumn('blogs', 'updated_by')) {
                $table->foreignId('updated_by')->nullable()->constrained('users')->onDelete('set null');
            }
        });

        // Add columns to seo_settings
        Schema::table('seo_settings', function (Blueprint $table) {
            if (!Schema::hasColumn('seo_settings', 'partner_id')) {
                $table->foreignId('partner_id')->nullable()->after('id')->constrained('partners')->onDelete('cascade');
            }
            if (!Schema::hasColumn('seo_settings', 'created_by')) {
                $table->foreignId('created_by')->nullable()->constrained('users')->onDelete('set null');
            }
            if (!Schema::hasColumn('seo_settings', 'updated_by')) {
                $table->foreignId('updated_by')->nullable()->constrained('users')->onDelete('set null');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('seo_settings', function (Blueprint $table) {
            $table->dropColumn(['partner_id', 'created_by', 'updated_by']);
        });
        Schema::table('blogs', function (Blueprint $table) {
            $table->dropColumn(['partner_id', 'blog_tag_id', 'created_by', 'updated_by']);
        });
        Schema::table('blog_categories', function (Blueprint $table) {
            $table->dropColumn(['partner_id', 'created_by', 'updated_by']);
        });
        Schema::table('blog_tags', function (Blueprint $table) {
            $table->dropColumn(['partner_id', 'created_by', 'updated_by']);
        });
    }
};
