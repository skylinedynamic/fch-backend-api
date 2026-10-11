<?php

namespace Tests\Feature\Archive;

use App\Models\Borrower\Borrower;
use App\Models\Partner\Partner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Archiving, against the acceptance criteria:
 *
 *   - the archived record leaves the default active list
 *   - searching by name / company / email still finds it
 *   - search results identify it as archived
 *   - the record and its history are intact; nothing is deleted
 *   - an authorised admin can restore it
 *
 * And the constraint that shaped the design: archiving is presentation only. It
 * must not touch `status`, which is what login and billing actually key on.
 */
class ArchiveRecordsTest extends TestCase
{
    use RefreshDatabase;

    private function partner(string $company, string $email): Partner
    {
        return Partner::create([
            'is_host' => false,
            'status' => Partner::STATUS_ACTIVE,
            'partner_type' => Partner::PARTNER_TYPE_WHITE_LABEL,
            'company_name' => $company,
            'full_name' => 'Owner of ' . $company,
            'email' => $email,
        ]);
    }

    /** A staff account to act as the one who archived a record. */
    private function admin(string $email): int
    {
        return DB::table('users')->insertGetId([
            'name' => 'Archivist',
            'email' => $email,
            'password' => bcrypt('secret'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function borrower(Partner $partner, string $first, string $email): Borrower
    {
        return Borrower::create([
            'partner_id' => $partner->id,
            'status' => Borrower::STATUS_ACTIVE,
            'first_name' => $first,
            'last_name' => 'Tester',
            'email' => $email,
        ]);
    }

    // ── The list ──────────────────────────────────────────────────────

    #[Test]
    public function an_archived_record_leaves_the_working_list(): void
    {
        $active = $this->partner('Active Co', 'active@example.test');
        $filed = $this->partner('Filed Co', 'filed@example.test');

        $filed->archive();

        $working = Partner::notArchived()->pluck('id')->all();

        $this->assertContains($active->id, $working);
        $this->assertNotContains($filed->id, $working, 'an archived partner must not clutter the active list');

        // ...but it is still there, and still reachable.
        $this->assertSame(2, Partner::count(), 'archiving must never delete');
        $this->assertSame([$filed->id], Partner::archived()->pluck('id')->all());
    }

    #[Test]
    public function the_archive_filter_covers_all_three_views(): void
    {
        $active = $this->partner('Active Co', 'a@example.test');
        $filed = $this->partner('Filed Co', 'f@example.test');
        $filed->archive();

        $ids = fn($filter) => Partner::archiveFilter($filter)->pluck('id')->sort()->values()->all();

        $this->assertSame([$active->id], $ids(null), 'no filter means the working list');
        $this->assertSame([$filed->id], $ids(true), 'truthy means archived only');
        $this->assertSame([$active->id, $filed->id], $ids('all'), "'all' ignores the distinction");
    }

    // ── Search ────────────────────────────────────────────────────────

    #[Test]
    public function an_archived_record_is_still_found_by_search(): void
    {
        $partner = $this->partner('Pentagon Funding Group', 'chuck@pentagon.test');
        $partner->archive();

        foreach (['Pentagon', 'chuck@pentagon.test'] as $term) {
            $found = Partner::search($term)->get();

            $this->assertCount(1, $found, "searching '{$term}' must still find the archived partner");
            $this->assertTrue($found->first()->is_archived, 'and it must be identifiable as archived');
        }
    }

    #[Test]
    public function an_archived_borrower_is_still_found_by_search(): void
    {
        $partner = $this->partner('Host Co', 'host@example.test');
        $borrower = $this->borrower($partner, 'Archibald', 'archibald@example.test');
        $borrower->archive();

        foreach (['Archibald', 'archibald@example.test'] as $term) {
            $found = Borrower::search($term)->get();

            $this->assertCount(1, $found, "searching '{$term}' must still find the archived borrower");
            $this->assertTrue($found->first()->is_archived);
        }
    }

    // ── Nothing is lost ───────────────────────────────────────────────

    #[Test]
    public function archiving_preserves_the_record_and_never_touches_access(): void
    {
        $partner = $this->partner('Intact Co', 'intact@example.test');
        $before = $partner->only(['company_name', 'full_name', 'email', 'status', 'partner_type']);

        $partner->archive();
        $partner->refresh();

        $this->assertSame($before, $partner->only(array_keys($before)), 'archiving must not rewrite the record');
        $this->assertSame(Partner::STATUS_ACTIVE, $partner->status, 'status decides access and must be untouched');
        $this->assertNull($partner->deleted_at, 'archiving is not deleting');
    }

    #[Test]
    public function archiving_records_who_did_it_and_is_not_rewritten_by_a_repeat(): void
    {
        $partner = $this->partner('Audited Co', 'audit@example.test');

        // archived_by is a real foreign key onto users, so the actor has to be a
        // real account - an invented id would only prove the key is not enforced.
        $filedBy = $this->admin('filed-by@example.test');
        $someoneElse = $this->admin('someone-else@example.test');

        $this->assertTrue($partner->archive(userId: $filedBy));
        $stampedAt = $partner->fresh()->archived_at;

        $this->assertSame($filedBy, (int) $partner->fresh()->archived_by);

        // A second archive is a no-op: it must not rewrite who filed it away.
        $this->assertFalse($partner->fresh()->archive(userId: $someoneElse));
        $this->assertSame($filedBy, (int) $partner->fresh()->archived_by);
        $this->assertEquals($stampedAt, $partner->fresh()->archived_at);
    }

    // ── Restore ───────────────────────────────────────────────────────

    #[Test]
    public function an_archived_record_can_be_restored_to_the_working_list(): void
    {
        $partner = $this->partner('Returning Co', 'return@example.test');

        $partner->archive();
        $this->assertNotContains($partner->id, Partner::notArchived()->pluck('id')->all());

        $this->assertTrue($partner->fresh()->unarchive());

        $restored = $partner->fresh();
        $this->assertFalse($restored->is_archived);
        $this->assertNull($restored->archived_by, 'the actor is cleared with the archive');
        $this->assertContains($partner->id, Partner::notArchived()->pluck('id')->all());

        // Restoring an active record is a no-op rather than an error.
        $this->assertFalse($restored->unarchive());
    }

    // ── No global scope ───────────────────────────────────────────────

    #[Test]
    public function archiving_does_not_hide_the_record_from_ordinary_queries(): void
    {
        // The reason this is not SoftDeletes. A global scope would hide an archived
        // row from every query in the system - including the searches, reports and
        // relations that must keep seeing it - and each of those would then need
        // remembering to opt out. Hiding is opt-in here, not opt-out.
        $partner = $this->partner('Everywhere Co', 'every@example.test');
        $borrower = $this->borrower($partner, 'Still', 'still@example.test');

        $partner->archive();
        $borrower->archive();

        $this->assertNotNull(Partner::find($partner->id), 'find() must still return it');
        $this->assertSame(1, Partner::where('company_name', 'Everywhere Co')->count());
        $this->assertSame(1, $partner->fresh()->borrowers()->count(), 'relations must not lose archived rows');
    }
}
