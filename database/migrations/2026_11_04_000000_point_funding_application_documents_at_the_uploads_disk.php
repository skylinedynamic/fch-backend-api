<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * BUG-016: application documents name the disk they are on. They were all
 * written to `public`; the app now keeps uploads on the `uploads` disk, which
 * sends a private folder's files to the private disk. Same paths, new disk
 * name, so a document keeps resolving once its folder moves.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('funding_application_documents')->where('disk', 'public')->update(['disk' => 'uploads']);
    }

    public function down(): void
    {
        DB::table('funding_application_documents')->where('disk', 'uploads')->update(['disk' => 'public']);
    }
};
