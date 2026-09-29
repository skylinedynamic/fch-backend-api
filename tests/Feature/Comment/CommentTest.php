<?php

namespace Tests\Feature\Comment;

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
 * The client comment feature: entity allowlisting, authorship rules
 * (edit/delete restricted to the author, or the author + host staff for
 * delete), and the same 4-level visibility model ActivityLog uses — because
 * a comment IS an ActivityLog entry (log_name = ActivityLog::TYPE_ACTIVITY),
 * not a separate table, matching CommentService.
 */
class CommentTest extends TestCase
{
    use RefreshDatabase;

    private Partner $hostPartner;
    private Partner $partner;
    private Partner $otherPartner;
    private Borrower $borrower;

    private User $hostAdmin;
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

        $this->hostAdmin = $this->makeUser(User::ADMIN, ['name' => 'Host Admin']);
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

    /**
     * Seeds a comment directly as CommentService::store() would build it —
     * an ActivityLog row, not a row in a dedicated comments table.
     */
    private function makeComment(User $author, string $body, string $visibility): ActivityLog
    {
        $activity = new ActivityLog([
            'log_name' => ActivityLog::TYPE_ACTIVITY,
            'description' => $body,
            'subject_type' => Borrower::class,
            'subject_id' => $this->borrower->id,
            'causer_type' => User::class,
            'causer_id' => $author->id,
            'visibility' => $visibility,
            'partner_id' => $this->partner->id,
            'borrower_id' => $this->borrower->id,
        ]);
        $activity->save();

        return $activity;
    }

    #[Test]
    public function an_unapproved_entity_type_is_rejected(): void
    {
        Passport::actingAs($this->partnerUser, [], 'api');

        $this->postJson('/api/v1/common/comments', [
            'entity_type' => 'user', // not in Comment::ALLOWED_ENTITY_TYPES
            'entity_id' => $this->partnerUser->id,
            'body' => 'Trying to comment on an arbitrary model',
        ])->assertStatus(422);
    }

    #[Test]
    public function a_comment_is_created_with_the_entitys_partner_and_borrower_ids_resolved(): void
    {
        Passport::actingAs($this->partnerUser, [], 'api');

        $response = $this->postJson('/api/v1/common/comments', [
            'entity_type' => 'borrower',
            'entity_id' => $this->borrower->id,
            'body' => 'First comment on this client',
            'visibility' => ActivityLog::VISIBILITY_PARTNER,
        ])->assertStatus(201);

        $comment = ActivityLog::findOrFail($response->json('data.id'));
        $this->assertSame($this->partner->id, $comment->partner_id);
        // Regression check: a Borrower entity IS the borrower, so its own id
        // — not a nonexistent $entity->borrower_id — must be stored.
        $this->assertSame($this->borrower->id, $comment->borrower_id);
        $this->assertSame($this->partnerUser->id, $comment->causer_id);
    }

    #[Test]
    public function visibility_follows_the_same_four_level_model_as_activity_log(): void
    {
        $everyone = $this->makeComment($this->hostAdmin, 'Everyone comment', ActivityLog::VISIBILITY_EVERYONE);
        $internal = $this->makeComment($this->hostAdmin, 'Internal comment', ActivityLog::VISIBILITY_INTERNAL);

        Passport::actingAs($this->partnerUser, [], 'api');
        $partnerIds = collect($this->getJson('/api/v1/common/comments?entity_type=borrower&entity_id=' . $this->borrower->id)
            ->assertOk()->json('data'))->pluck('id')->all();
        $this->assertContains($everyone->id, $partnerIds);
        $this->assertNotContains($internal->id, $partnerIds, 'a partner user must not see an internal-only comment');

        Passport::actingAs($this->otherPartnerUser, [], 'api');
        $otherPartnerIds = collect($this->getJson('/api/v1/common/comments?entity_type=borrower&entity_id=' . $this->borrower->id)
            ->assertOk()->json('data'))->pluck('id')->all();
        $this->assertNotContains($everyone->id, $otherPartnerIds, 'a different partner must not see this partner-scoped record at all');

        Passport::actingAs($this->hostAdmin, [], 'api');
        $hostIds = collect($this->getJson('/api/v1/common/comments?entity_type=borrower&entity_id=' . $this->borrower->id)
            ->assertOk()->json('data'))->pluck('id')->all();
        $this->assertContains($everyone->id, $hostIds);
        $this->assertContains($internal->id, $hostIds);
    }

    #[Test]
    public function only_the_author_may_edit_their_own_comment(): void
    {
        $comment = $this->makeComment($this->partnerUser, 'Original text', ActivityLog::VISIBILITY_PARTNER);

        Passport::actingAs($this->hostAdmin, [], 'api');
        $this->putJson("/api/v1/common/comments/{$comment->id}", ['body' => 'Hijacked'])
            ->assertStatus(403);
        $this->assertSame('Original text', $comment->fresh()->description);

        Passport::actingAs($this->partnerUser, [], 'api');
        $this->putJson("/api/v1/common/comments/{$comment->id}", ['body' => 'Edited by author'])
            ->assertSuccessful()
            ->assertJsonPath('data.body', 'Edited by author');
    }

    #[Test]
    public function the_author_or_a_host_user_may_delete_a_comment_but_nobody_else_can(): void
    {
        $comment = $this->makeComment($this->partnerUser, 'Delete me', ActivityLog::VISIBILITY_PARTNER);

        Passport::actingAs($this->otherPartnerUser, [], 'api');
        $this->deleteJson("/api/v1/common/comments/{$comment->id}")->assertStatus(403);

        Passport::actingAs($this->hostAdmin, [], 'api');
        $this->deleteJson("/api/v1/common/comments/{$comment->id}")->assertSuccessful();
        $this->assertSoftDeleted('activity_log', ['id' => $comment->id]);
    }

    #[Test]
    public function the_borrower_route_lets_a_borrower_post_and_read_their_own_comments(): void
    {
        Passport::actingAs($this->borrowerUser, [], 'api');

        $this->postJson('/api/v1/borrower/comments', [
            'entity_type' => 'borrower',
            'entity_id' => $this->borrower->id,
            'body' => 'A question from the borrower',
            'visibility' => ActivityLog::VISIBILITY_EVERYONE,
        ])->assertStatus(201);

        $this->getJson('/api/v1/borrower/comments?entity_type=borrower&entity_id=' . $this->borrower->id)
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }
}
