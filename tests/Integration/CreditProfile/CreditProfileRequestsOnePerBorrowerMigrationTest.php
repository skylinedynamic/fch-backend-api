<?php

namespace Tests\Integration\CreditProfile;

use App\Models\Borrower\Borrower;
use App\Models\CreditAuthorization\CreditAuthorizationConsent;
use App\Models\Partner\Partner;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The migration that makes credit_profile_requests one row per borrower.
 *
 * Before borrower_id can be unique, borrowers who already have several rows are
 * merged into their latest one - a live row over a soft-deleted one, then the
 * most recently updated - and the FCRA consents that pointed at the merged rows
 * are repointed, so no authorisation loses the request it belongs to.
 *
 * Run against real duplicates: the index the migration adds is dropped first so
 * they can be inserted, then the migration is run again.
 */
class CreditProfileRequestsOnePerBorrowerMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = 'database/migrations/2026_10_15_120000_make_credit_profile_requests_one_per_borrower.php';

    private int $partnerId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->partnerId = Partner::create([
            'is_host' => true,
            'status' => Partner::STATUS_ACTIVE,
            'partner_type' => Partner::PARTNER_TYPE_CERTIFIED,
            'company_name' => 'Host Co',
            'full_name' => 'Host Co',
            'email' => 'host@example.test',
        ])->id;

        // Back to the table as it was before the migration.
        Schema::table('credit_profile_requests', fn ($table) => $table->dropUnique('credit_profile_requests_borrower_id_unique'));
    }

    private function migrate(): void
    {
        (require base_path(self::MIGRATION))->up();
    }

    private function borrower(string $email): int
    {
        return Borrower::create([
            'partner_id' => $this->partnerId,
            'status' => Borrower::STATUS_ACTIVE,
            'first_name' => 'Client',
            'last_name' => $email,
            'email' => $email,
        ])->id;
    }

    private function row(int $borrowerId, string $city, string $updatedAt, ?string $deletedAt = null): int
    {
        return DB::table('credit_profile_requests')->insertGetId([
            'borrower_id' => $borrowerId,
            'first_name' => 'Client',
            'last_name' => 'Row',
            'address' => '1 Main St',
            'city' => $city,
            'state' => 'Texas',
            'zip' => '78701',
            'ssn' => '123456789',
            'date_of_birth' => '1990-01-01',
            'phone' => '5125550100',
            'email' => 'client@example.test',
            'created_at' => Carbon::parse('2026-09-01 09:00:00'),
            'updated_at' => Carbon::parse($updatedAt),
            'deleted_at' => $deletedAt ? Carbon::parse($deletedAt) : null,
        ]);
    }

    private function consentFor(int $borrowerId, int $requestId): int
    {
        return CreditAuthorizationConsent::create([
            'borrower_id' => $borrowerId,
            'credit_profile_request_id' => $requestId,
            'authorization_version' => '1.0',
            'authorization_url' => 'https://example.test/disclosure',
            'credit_authorization_text' => 'x',
            'e_consent_text' => 'y',
            'credit_authorization_accepted' => true,
            'e_consent_accepted' => true,
            'consented_at' => Carbon::parse('2026-09-01 09:00:00'),
        ])->id;
    }

    #[Test]
    public function duplicates_are_merged_into_the_borrowers_latest_live_request(): void
    {
        $borrower = $this->borrower('dup@example.test');
        $older = $this->row($borrower, 'Older', '2026-10-01 09:00:00');
        $latest = $this->row($borrower, 'Latest', '2026-10-05 09:00:00');
        // Newest of all, but deleted: a live row is kept over it.
        $deleted = $this->row($borrower, 'Deleted', '2026-10-09 09:00:00', '2026-10-10 09:00:00');

        $this->migrate();

        $this->assertSame([$latest], DB::table('credit_profile_requests')->where('borrower_id', $borrower)->pluck('id')->all());
        $this->assertSame('Latest', DB::table('credit_profile_requests')->where('id', $latest)->value('city'));
        $this->assertSame(0, DB::table('credit_profile_requests')->whereIn('id', [$older, $deleted])->count());
    }

    #[Test]
    public function the_consents_of_merged_requests_point_at_the_kept_one(): void
    {
        $borrower = $this->borrower('consents@example.test');
        $older = $this->row($borrower, 'Older', '2026-10-01 09:00:00');
        $latest = $this->row($borrower, 'Latest', '2026-10-05 09:00:00');
        $onOlder = $this->consentFor($borrower, $older);
        $onLatest = $this->consentFor($borrower, $latest);

        $this->migrate();

        // Both authorisations survive, both on the request that is left.
        $this->assertSame(
            [$onOlder => $latest, $onLatest => $latest],
            DB::table('credit_authorization_consents')->whereIn('id', [$onOlder, $onLatest])->orderBy('id')->pluck('credit_profile_request_id', 'id')->all()
        );
    }

    #[Test]
    public function a_borrower_with_only_deleted_requests_keeps_the_latest_of_them(): void
    {
        $borrower = $this->borrower('all-deleted@example.test');
        $this->row($borrower, 'Older', '2026-10-01 09:00:00', '2026-10-02 09:00:00');
        $newer = $this->row($borrower, 'Newer', '2026-10-05 09:00:00', '2026-10-06 09:00:00');

        $this->migrate();

        $this->assertSame([$newer], DB::table('credit_profile_requests')->where('borrower_id', $borrower)->pluck('id')->all());
    }

    #[Test]
    public function other_borrowers_are_left_alone(): void
    {
        $single = $this->borrower('single@example.test');
        $only = $this->row($single, 'Only', '2026-10-01 09:00:00');
        $dup = $this->borrower('dup@example.test');
        $this->row($dup, 'A', '2026-10-01 09:00:00');
        $this->row($dup, 'B', '2026-10-02 09:00:00');

        $this->migrate();

        $this->assertSame([$only], DB::table('credit_profile_requests')->where('borrower_id', $single)->pluck('id')->all());
    }

    #[Test]
    public function afterwards_a_second_request_for_a_borrower_is_refused(): void
    {
        $borrower = $this->borrower('unique@example.test');
        $this->row($borrower, 'A', '2026-10-01 09:00:00');

        $this->migrate();

        $this->expectException(UniqueConstraintViolationException::class);
        $this->row($borrower, 'B', '2026-10-02 09:00:00');
    }
}
