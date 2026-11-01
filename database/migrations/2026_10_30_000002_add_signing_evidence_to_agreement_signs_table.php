<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The signed record used to be whatever HTML the signer's browser sent. It is
 * now built on the server (SignedAgreementBuilder); a SHA-256 of it and the
 * signer's browser are kept with the IP, so the record can be shown not to
 * have changed since it was signed. Null for signatures made before.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agreement_signs', function (Blueprint $table) {
            $table->char('content_hash', 64)->nullable()->after('content_html');
            $table->string('user_agent', 512)->nullable()->after('ip_address');
        });
    }

    public function down(): void
    {
        Schema::table('agreement_signs', function (Blueprint $table) {
            $table->dropColumn(['content_hash', 'user_agent']);
        });
    }
};
