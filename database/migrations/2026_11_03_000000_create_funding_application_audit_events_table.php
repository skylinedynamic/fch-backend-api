<?php

use App\Models\Audit\FundingApplicationAuditEvent;
use App\Services\Audit\FundingApplicationAuditTrail;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The funding application audit trail replaces download_histories.
 *
 * No foreign key on funding_application_id on purpose: the record of an
 * application must outlive its row (download_histories cascaded on delete).
 *
 * The download_histories rows - real records of who downloaded what, when -
 * are carried over as `downloaded` events marked legacy, in their original
 * order, chained as they are imported. Their files were download copies that
 * later downloads overwrote, so no file is linked to them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('funding_application_audit_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('funding_application_id')->index();
            $table->unsignedBigInteger('partner_id')->nullable()->index();
            $table->unsignedBigInteger('borrower_id')->nullable()->index();
            $table->string('event', 50)->index();
            $table->unsignedBigInteger('actor_user_id')->nullable()->index();
            $table->string('actor_name')->nullable();
            $table->string('actor_type', 50)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->json('changes')->nullable();
            $table->json('context')->nullable();
            $table->string('file_hash', 64)->nullable();
            $table->string('file_path')->nullable();
            $table->timestamp('occurred_at');
            $table->boolean('is_legacy')->default(false);
            $table->string('prev_hash', 64)->nullable();
            $table->string('hash', 64);
            $table->index(['funding_application_id', 'id']);
        });

        if (Schema::hasTable('download_histories')) {
            $this->importDownloadHistories();
            Schema::drop('download_histories');
        }
    }

    public function down(): void
    {
        Schema::create('download_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->onDelete('set null');
            $table->string('user_name')->nullable();
            $table->string('user_type')->nullable();
            $table->foreignId('funding_application_id')->nullable()->constrained('funding_applications')->onDelete('cascade');
            $table->string('application_number')->nullable();
            $table->string('application_version')->nullable();
            $table->string('certificate_id')->nullable();
            $table->string('file_type');
            $table->string('action')->nullable();
            $table->string('sha256_hash');
            $table->string('ip_address')->nullable();
            $table->json('details')->nullable();
            $table->timestamp('downloaded_at');
            $table->timestamps();
        });

        Schema::dropIfExists('funding_application_audit_events');
    }

    private function importDownloadHistories(): void
    {
        $trail = app(FundingApplicationAuditTrail::class);
        $applications = DB::table('funding_applications')->pluck('partner_id', 'id');
        $borrowers = DB::table('funding_applications')->pluck('borrower_id', 'id');

        DB::table('download_histories')->whereNotNull('funding_application_id')->orderBy('id')
            ->chunk(500, function ($rows) use ($trail, $applications, $borrowers) {
                foreach ($rows as $row) {
                    $details = json_decode((string) $row->details, true) ?: [];
                    // 'generated' rows were the copies kept at signing, not downloads.
                    $generated = $row->action === 'generated';

                    $trail->append([
                        'funding_application_id' => (int) $row->funding_application_id,
                        'partner_id' => $applications[$row->funding_application_id] ?? null,
                        'borrower_id' => $borrowers[$row->funding_application_id] ?? null,
                        'event' => $generated ? FundingApplicationAuditEvent::EVENT_SIGNED_COPY_KEPT : FundingApplicationAuditEvent::EVENT_DOWNLOADED,
                        'actor_user_id' => $row->user_id,
                        'actor_name' => $row->user_name,
                        'actor_type' => $row->user_type,
                        'ip_address' => $row->ip_address,
                        'user_agent' => null,
                        'changes' => null,
                        // Never a password, even one an old row still holds.
                        'context' => array_filter([
                            'file_type' => $row->file_type,
                            'password_protected' => $generated ? null : $row->action === 'downloaded_with_password',
                            'application_version' => $row->application_version,
                            'certificate_id' => $row->certificate_id,
                            'os' => $details['os'] ?? null,
                            'browser' => $details['browser'] ?? null,
                            'device' => $details['device'] ?? null,
                            'imported_from' => 'download_histories#' . $row->id,
                        ], fn ($value) => $value !== null),
                        'file_hash' => $row->sha256_hash ?: null,
                        'file_path' => null,
                        'occurred_at' => $row->downloaded_at,
                        'is_legacy' => true,
                    ]);
                }
            });
    }
};
