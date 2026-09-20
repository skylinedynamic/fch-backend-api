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
        Schema::table('blogs', function (Blueprint $table) {
            $table->dropForeign(['blog_tag_id']);
            $table->dropIndex('blogs_blog_tag_id_foreign');
        });

        Schema::table('blogs', function (Blueprint $table) {
            $table->json('blog_tag_id')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('blogs', function (Blueprint $table) {
            // It's hard to safely revert json to foreignId with data, 
            // but we provide a simple reverse.
            $table->unsignedBigInteger('blog_tag_id')->nullable()->change();
            $table->foreign('blog_tag_id')->references('id')->on('blog_tags')->onDelete('set null');
        });
    }
};
