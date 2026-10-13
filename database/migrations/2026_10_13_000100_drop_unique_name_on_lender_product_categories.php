<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Free a deleted product category's name (findings O32).
 *
 * Categories are soft-deleted, but `name` carried a database unique index,
 * so a deleted category's row kept its name taken for good - with no way to
 * see or restore it. Uniqueness among categories that are not deleted is
 * enforced by the store/update requests instead (an index cannot express it
 * in MySQL: a unique (name, deleted_at) lets live rows repeat, as NULLs never
 * collide). A plain index keeps the name lookups fast.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lender_product_categories', function (Blueprint $table) {
            $table->dropUnique(['name']);
            $table->index('name');
        });
    }

    public function down(): void
    {
        Schema::table('lender_product_categories', function (Blueprint $table) {
            $table->dropIndex(['name']);
            $table->unique('name');
        });
    }
};
