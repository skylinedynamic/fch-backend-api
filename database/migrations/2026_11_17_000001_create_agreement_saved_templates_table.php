<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agreement Builder › Save as Template: a partner keeps the agreement it is
 * editing as a named template to start from later. A partner saves its
 * borrower agreement, the host both its partner and its borrower agreement.
 * Only the builder's blocks are kept (template_json); the HTML is rebuilt from
 * them when the template is used and the agreement saved.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agreement_saved_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('partner_id')->constrained('partners')->cascadeOnDelete();
            $table->string('slug', 32);
            $table->string('name', 100);
            $table->longText('template_json');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->index(['partner_id', 'slug']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agreement_saved_templates');
    }
};
