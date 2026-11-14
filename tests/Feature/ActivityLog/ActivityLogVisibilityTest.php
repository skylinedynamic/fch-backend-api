<?php

namespace Tests\Feature\ActivityLog;

use App\Models\ActivityLog\ActivityLog;
use App\Models\Borrower\Borrower;
use App\Models\Partner\Partner;
use App\Models\User\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\Passport;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The 4-level Activity Timeline visibility model (private/internal/partner/
 * everyone), against the acceptance criteria:
 *
 *   - visibility is required when submitting a manual activity
 *   - Host sees internal + partner + everyone (+ their own private)
 *   - Partner sees partner + everyone (+ their own private), scoped to their
 *     own partner's records
 *   - Borrower sees only everyone (+ their own private)
 *   - Private is visible to nobody but its author - not even other host staff
 *   - visibility can be edited afterward, but only by an authorized host user,
 *     and independently of who authored the row
 */
class ActivityLogVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private Partner $hostPartner;
    private Partner $partner;
    private Partner $otherPartner;
    private Borrower $borrower;

    private User $hostAdmin;
    private User $otherHostAdmin;
    private User $partnerUser;
    private User $otherPartnerUser;
    private User $borrowerUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->hostPartner = Partner::create([
            'is_host' => true,
            'status' => Partner::STATUS_ACTIVE,
            'company_name' => 'FCH Host',
            'full_name' => 'FCH Host',
            'email' => 'host-' . uniqid() . '@example.test',
        ]);

        $this->partner = Partner::create([
            'is_host' => false,
            'status' => Partner::STATUS_ACTIVE,
            'company_name' => 'Four Corner Funding',
            'full_name' => 'Four Corner Funding',
            'email' => 'partner-' . uniqid() . '@example.test',
        ]);

        $this->otherPartner = Partner::create([
            'is_host' => false,
            'status' => Partner::STATUS_ACTIVE,
            'company_name' => 'Another Partner Co',
            'full_name' => 'Another Partner Co',
            'email' => 'other-partner-' . uniqid() . '@example.test',
        ]);

        $this->borrower = Borrower::create([
            'partner_id' => $this->partner->id,
            'status' => Borrower::STATUS_ACTIVE,
            'first_name' => 'Johnathan',
            'last_name' => 'Smithfield',
            'email' => 'borrower-' . uniqid() . '@example.test',
        ]);

        // Every user belongs to a partner - the host's team to the host.
        $this->hostAdmin = $this->makeUser(User::ADMIN, ['name' => 'Host Admin', 'partner_id' => $this->hostPartner->id]);
        $this->otherHostAdmin = $this->makeUser(User::ADMIN, ['name' => 'Other Host Admin', 'partner_id' => $this->hostPartner->id]);
        $this->partnerUser = $this->makeUser(User::PARTNER, [
            'name' => 'Jason Rodriguez',
            'partner_id' => $this->partner->id,
        ]);
        $this->otherPartnerUser = $this->makeUser(User::PARTNER, [
            'name' => 'Other Partner User',
            'partner_id' => $this->otherPartner->id,
        ]);
        $this->borrowerUser = $this->makeUser(User::BORROWER, [
            'name' => 'Johnathan Smithfield',
            'entity_type' => Borrower::class,
            'entity_id' => $this->borrower->id,
            'partner_id' => $this->partner->id,
        ]);
    }

    private function makeUser(string $userType, array $attributes = []): User
    {
        $id = DB::table('users')->insertGetId(array_merge([
            'name' => 'Test User',
            'email' => 'user-' . uniqid() . '@example.test',
            'password' => bcrypt('secret'),
            'user_type' => $userType,
            'created_at' => now(),
            'updated_at' => now(),
        ], $attributes));

        return User::findOrFail($id);
    }

    private function activity(User $causer, string $visibility, array $attributes = []): ActivityLog
    {
        return ActivityLog::create(array_merge([
            'log_name' => 'activity',
            'subject_type' => Borrower::class,
            'subject_id' => $this->borrower->id,
            'causer_id' => $causer->id,
            'causer_type' => User::class,
            'visibility' => $visibility,
            'status' => 'log',
            'event' => 'Test entry',
            'description' => 'Test entry',
            'properties' => ['partner_id' => $this->partner->id, 'borrower_id' => $this->borrower->id],
        ], $attributes));
    }

    // ── The visibility matrix ────────────────────────────────────────

    #[Test]
    public function each_role_sees_exactly_the_levels_the_spec_grants_them(): void
    {
        // Authored by a host user who is NOT the viewer under test, and not the
        // partner/borrower either, so "you always see your own row" never masks
        // whether the level-based rule itself is correct.
        $rows = [];
        foreach ([ActivityLog::VISIBILITY_PRIVATE, ActivityLog::VISIBILITY_INTERNAL, ActivityLog::VISIBILITY_PARTNER, ActivityLog::VISIBILITY_EVERYONE] as $level) {
            $rows[$level] = $this->activity($this->otherHostAdmin, $level)->id;
        }

        $seenBy = fn (User $user) => ActivityLog::visibleTo($user)
            ->whereIn('id', array_values($rows))
            ->pluck('visibility')->sort()->values()->all();

        $this->assertEqualsCanonicalizing(
            [ActivityLog::VISIBILITY_INTERNAL, ActivityLog::VISIBILITY_PARTNER, ActivityLog::VISIBILITY_EVERYONE],
            $seenBy($this->hostAdmin),
            'Host must see internal + partner + everyone, and nobody else\'s private'
        );

        $this->assertEqualsCanonicalizing(
            [ActivityLog::VISIBILITY_PARTNER, ActivityLog::VISIBILITY_EVERYONE],
            $seenBy($this->partnerUser),
            'Partner must see partner + everyone only'
        );

        $this->assertEqualsCanonicalizing(
            [ActivityLog::VISIBILITY_EVERYONE],
            $seenBy($this->borrowerUser),
            'Borrower must see everyone only'
        );
    }

    #[Test]
    public function a_private_activity_is_visible_to_nobody_but_its_author_not_even_other_host_staff(): void
    {
        $private = $this->activity($this->otherHostAdmin, ActivityLog::VISIBILITY_PRIVATE);

        $this->assertTrue(
            ActivityLog::visibleTo($this->otherHostAdmin)->whereKey($private->id)->exists(),
            'the author must see their own private entry'
        );
        $this->assertFalse(
            ActivityLog::visibleTo($this->hostAdmin)->whereKey($private->id)->exists(),
            'another host admin must NOT see someone else\'s private entry'
        );
        $this->assertFalse(ActivityLog::visibleTo($this->partnerUser)->whereKey($private->id)->exists());
        $this->assertFalse(ActivityLog::visibleTo($this->borrowerUser)->whereKey($private->id)->exists());
    }

    #[Test]
    public function a_partner_never_sees_another_partners_records_regardless_of_visibility_level(): void
    {
        $everyoneRow = $this->activity($this->otherHostAdmin, ActivityLog::VISIBILITY_EVERYONE);

        $this->assertTrue(ActivityLog::visibleTo($this->partnerUser)->whereKey($everyoneRow->id)->exists());
        $this->assertFalse(
            ActivityLog::visibleTo($this->otherPartnerUser)->whereKey($everyoneRow->id)->exists(),
            'a different partner, scoped to a different partner_id, must not see this record at all'
        );
    }

    // ── Creating activities via the API ──────────────────────────────

    #[Test]
    public function visibility_is_required_when_creating_a_manual_activity(): void
    {
        Passport::actingAs($this->hostAdmin, [], 'api');

        $this->postJson('/api/v1/common/activity-logs', [
            'log_type' => 'activity',
            'borrower_id' => $this->borrower->id,
            'reference' => 'Task',
            'subject' => 'Missing visibility',
            'description' => 'No visibility field sent',
            'activity_date' => now()->format('Y-m-d H:i:s'),
        ])->assertStatus(422)->assertJsonPath('errors.visibility', 'Please select who can see this activity.');
    }

    #[Test]
    public function a_host_user_can_create_an_activity_at_any_of_the_four_levels(): void
    {
        Passport::actingAs($this->hostAdmin, [], 'api');

        foreach (['private', 'internal', 'partner', 'everyone'] as $level) {
            $response = $this->postJson('/api/v1/common/activity-logs', [
                'log_type' => 'activity',
                'borrower_id' => $this->borrower->id,
                'reference' => 'Task',
                'subject' => "Host note ({$level})",
                'description' => 'Testing host visibility options',
                'activity_date' => now()->format('Y-m-d H:i:s'),
                'visibility' => $level,
            ])->assertSuccessful();

            $this->assertSame($level, $response->json('data.visibility'));
        }
    }

    #[Test]
    public function a_partner_user_cannot_create_an_internal_only_activity(): void
    {
        Passport::actingAs($this->partnerUser, [], 'api');

        $this->postJson('/api/v1/common/activity-logs', [
            'log_type' => 'activity',
            'borrower_id' => $this->borrower->id,
            'reference' => 'Task',
            'subject' => 'Partner trying to go internal',
            'description' => 'Should be rejected',
            'activity_date' => now()->format('Y-m-d H:i:s'),
            'visibility' => 'internal',
        ])->assertStatus(422)->assertJsonPath('errors.visibility', 'That visibility level is not available to you.');
    }

    #[Test]
    public function a_partner_user_can_create_private_partner_and_everyone_activities(): void
    {
        Passport::actingAs($this->partnerUser, [], 'api');

        foreach (['private', 'partner', 'everyone'] as $level) {
            $response = $this->postJson('/api/v1/common/activity-logs', [
                'log_type' => 'activity',
                'borrower_id' => $this->borrower->id,
                'reference' => 'Task',
                'subject' => "Partner note ({$level})",
                'description' => 'Testing partner visibility options',
                'activity_date' => now()->format('Y-m-d H:i:s'),
                'visibility' => $level,
            ])->assertSuccessful();

            $this->assertSame($level, $response->json('data.visibility'));
        }
    }

    #[Test]
    public function an_email_activity_derives_visibility_from_the_legacy_recipient_flags(): void
    {
        Passport::actingAs($this->hostAdmin, [], 'api');

        $toBorrower = $this->postJson('/api/v1/common/activity-logs', [
            'log_type' => 'email',
            'borrower_id' => $this->borrower->id,
            'subject' => 'Email to borrower',
            'description' => 'Body',
            'to_emails' => 'borrower@example.test',
            'send_to_borrower' => '1',
        ])->assertSuccessful();
        $this->assertSame('everyone', $toBorrower->json('data.visibility'));

        $toHost = $this->postJson('/api/v1/common/activity-logs', [
            'log_type' => 'email',
            'borrower_id' => $this->borrower->id,
            'subject' => 'Email to host',
            'description' => 'Body',
            'to_emails' => 'host@example.test',
            'send_to_host' => '1',
        ])->assertSuccessful();
        $this->assertSame('partner', $toHost->json('data.visibility'));

        $unflagged = $this->postJson('/api/v1/common/activity-logs', [
            'log_type' => 'email',
            'borrower_id' => $this->borrower->id,
            'subject' => 'Unflagged email',
            'description' => 'Body',
            'to_emails' => 'nobody@example.test',
        ])->assertSuccessful();
        $this->assertSame('internal', $unflagged->json('data.visibility'));
    }

    #[Test]
    public function the_borrower_store_endpoint_only_accepts_private_or_everyone(): void
    {
        Passport::actingAs($this->borrowerUser, [], 'api');

        $this->postJson('/api/v1/borrower/activity-logs', [
            'log_type' => 'activity',
            'borrower_id' => $this->borrower->id,
            'reference' => 'Task',
            'subject' => 'Borrower trying to go internal',
            'description' => 'Should be rejected',
            'activity_date' => now()->format('Y-m-d H:i:s'),
            'visibility' => 'internal',
        ])->assertStatus(422);

        $ok = $this->postJson('/api/v1/borrower/activity-logs', [
            'log_type' => 'activity',
            'borrower_id' => $this->borrower->id,
            'reference' => 'Task',
            'subject' => 'Borrower note',
            'description' => 'Should be accepted',
            'activity_date' => now()->format('Y-m-d H:i:s'),
            'visibility' => 'everyone',
        ])->assertSuccessful();

        $this->assertSame('everyone', $ok->json('data.visibility'));
    }

    // ── Editing visibility afterward ─────────────────────────────────

    #[Test]
    public function the_owner_can_change_their_own_manual_activitys_visibility_via_the_normal_edit(): void
    {
        Passport::actingAs($this->hostAdmin, [], 'api');
        $log = $this->activity($this->hostAdmin, ActivityLog::VISIBILITY_INTERNAL);

        $this->putJson("/api/v1/common/activity-logs/{$log->id}", [
            'visibility' => 'everyone',
        ])->assertSuccessful()->assertJsonPath('data.visibility', 'everyone');

        $this->assertSame('everyone', $log->fresh()->visibility);
    }

    #[Test]
    public function only_a_host_user_may_reclassify_any_activitys_visibility(): void
    {
        $log = $this->activity($this->otherPartnerUser, ActivityLog::VISIBILITY_PARTNER);

        Passport::actingAs($this->partnerUser, [], 'api');
        $this->patchJson("/api/v1/common/activity-logs/{$log->id}/visibility", [
            'visibility' => 'internal',
        ])->assertStatus(403);
        $this->assertSame('partner', $log->fresh()->visibility, 'a non-host must never change it');

        Passport::actingAs($this->hostAdmin, [], 'api');
        $this->patchJson("/api/v1/common/activity-logs/{$log->id}/visibility", [
            'visibility' => 'internal',
        ])->assertSuccessful()->assertJsonPath('data.visibility', 'internal');

        $this->assertSame('internal', $log->fresh()->visibility, 'a host may reclassify a row they never authored');
    }

    #[Test]
    public function updating_visibility_rejects_an_invalid_level(): void
    {
        Passport::actingAs($this->hostAdmin, [], 'api');
        $log = $this->activity($this->hostAdmin, ActivityLog::VISIBILITY_INTERNAL);

        $this->patchJson("/api/v1/common/activity-logs/{$log->id}/visibility", [
            'visibility' => 'made-up-level',
        ])->assertStatus(422);
    }

    // ── The list endpoint end-to-end (full HTTP + DB integration) ────

    #[Test]
    public function the_list_endpoint_returns_only_what_each_role_may_see(): void
    {
        $ids = [
            'private' => $this->activity($this->otherHostAdmin, ActivityLog::VISIBILITY_PRIVATE, ['description' => 'Private note'])->id,
            'internal' => $this->activity($this->otherHostAdmin, ActivityLog::VISIBILITY_INTERNAL, ['description' => 'Internal note'])->id,
            'partner' => $this->activity($this->otherHostAdmin, ActivityLog::VISIBILITY_PARTNER, ['description' => 'Partner note'])->id,
            'everyone' => $this->activity($this->otherHostAdmin, ActivityLog::VISIBILITY_EVERYONE, ['description' => 'Everyone note'])->id,
        ];

        Passport::actingAs($this->hostAdmin, [], 'api');
        $hostIds = collect($this->postJson('/api/v1/common/activity-logs/fetch-all-activities', [
            'borrower_id' => $this->borrower->id,
            'limit' => 50,
        ])->assertOk()->json('data'))->pluck('id')->all();
        $this->assertEqualsCanonicalizing([$ids['internal'], $ids['partner'], $ids['everyone']], $hostIds);

        Passport::actingAs($this->partnerUser, [], 'api');
        $partnerIds = collect($this->postJson('/api/v1/common/activity-logs/fetch-all-activities', [
            'borrower_id' => $this->borrower->id,
            'limit' => 50,
        ])->assertOk()->json('data'))->pluck('id')->all();
        $this->assertEqualsCanonicalizing([$ids['partner'], $ids['everyone']], $partnerIds);

        Passport::actingAs($this->borrowerUser, [], 'api');
        $borrowerIds = collect($this->postJson('/api/v1/borrower/activity-logs/fetch-all-activities', [
            'borrower_id' => $this->borrower->id,
            'limit' => 50,
        ])->assertOk()->json('data'))->pluck('id')->all();
        $this->assertEqualsCanonicalizing([$ids['everyone']], $borrowerIds);
    }
    // ── The client portal's application Activity panel (BUG-103) ──────

    /**
     * Defect (BUG-103): a note added on the client's page is about the client
     * (subject Borrower), but the client portal's only activity view asks for
     * one application's entries, so an "Everyone" note never reached the
     * client. With include_client_notes the panel gets the application's
     * entries and the client's own Everyone notes - never Private, Internal
     * or Partner ones, and never another client's.
     */
    #[Test]
    public function the_application_panel_also_shows_the_clients_everyone_notes(): void
    {
        $applicationId = DB::table('funding_applications')->insertGetId([
            'application_number' => 'FUND-' . uniqid(),
            'borrower_id' => $this->borrower->id,
            'partner_id' => $this->partner->id,
            'status' => \App\Models\Borrower\FundingApplication::PENDING,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $statusChange = $this->activity($this->otherHostAdmin, ActivityLog::VISIBILITY_EVERYONE, [
            'subject_type' => \App\Models\Borrower\FundingApplication::class,
            'subject_id' => $applicationId,
            'description' => 'Status changed',
        ])->id;
        $everyone = $this->activity($this->otherHostAdmin, ActivityLog::VISIBILITY_EVERYONE, ['description' => 'Everyone note'])->id;
        $this->activity($this->otherHostAdmin, ActivityLog::VISIBILITY_PRIVATE, ['description' => 'Private note']);
        $this->activity($this->otherHostAdmin, ActivityLog::VISIBILITY_INTERNAL, ['description' => 'Internal note']);
        $this->activity($this->otherHostAdmin, ActivityLog::VISIBILITY_PARTNER, ['description' => 'Partner note']);

        $neighbour = Borrower::create([
            'partner_id' => $this->partner->id,
            'status' => Borrower::STATUS_ACTIVE,
            'first_name' => 'Other',
            'last_name' => 'Client',
            'email' => 'neighbour-' . uniqid() . '@example.test',
        ]);
        $this->activity($this->otherHostAdmin, ActivityLog::VISIBILITY_EVERYONE, [
            'subject_id' => $neighbour->id,
            'description' => 'Another client note',
            'properties' => ['partner_id' => $this->partner->id, 'borrower_id' => $neighbour->id],
        ]);

        Passport::actingAs($this->borrowerUser, [], 'api');

        $withNotes = collect($this->postJson('/api/v1/borrower/activity-logs/fetch-all-activities', [
            'funding_application_id' => $applicationId,
            'include_client_notes' => true,
            'limit' => 50,
        ])->assertOk()->json('data'))->pluck('id')->all();
        $this->assertEqualsCanonicalizing([$statusChange, $everyone], $withNotes);

        // Without the option the panel is the application's entries only, as before.
        $applicationOnly = collect($this->postJson('/api/v1/borrower/activity-logs/fetch-all-activities', [
            'funding_application_id' => $applicationId,
            'limit' => 50,
        ])->assertOk()->json('data'))->pluck('id')->all();
        $this->assertSame([$statusChange], $applicationOnly);
    }

    /**
     * BUG-156: the client portal titles a staff note by its Summary, so the
     * client feed must carry it (`subject`) next to the log type that marks the
     * entry as a note - a summary-only note has nothing else to show.
     */
    #[Test]
    public function the_client_feed_carries_a_notes_summary(): void
    {
        $note = $this->activity($this->otherHostAdmin, ActivityLog::VISIBILITY_EVERYONE, [
            'event' => 'QA-TEST Run2 vis Everyone',
            'description' => '',
            'properties' => [
                'partner_id' => $this->partner->id,
                'borrower_id' => $this->borrower->id,
                'log_type' => 'activity',
                'subject' => 'QA-TEST Run2 vis Everyone',
                'description' => null,
            ],
        ]);

        Passport::actingAs($this->borrowerUser, [], 'api');

        $row = collect($this->postJson('/api/v1/borrower/activity-logs/fetch-all-activities', [
            'borrower_id' => $this->borrower->id,
            'limit' => 50,
        ])->assertOk()->json('data'))->firstWhere('id', $note->id);

        $this->assertSame('activity', $row['log_type_raw']);
        $this->assertSame('QA-TEST Run2 vis Everyone', $row['subject']);
    }
}
