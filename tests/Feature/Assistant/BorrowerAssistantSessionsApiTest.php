<?php

namespace Tests\Feature\Assistant;

use App\Http\Controllers\Api\V1\Borrower\Assistant\BorrowerAssistantController;
use App\Models\Assistant\ChatConversation;
use App\Models\Partner\Partner;
use App\Models\User\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/**
 * The borrower support assistant's sessions (/borrower/assistant/sessions,
 * /conversations/{sessionId}/messages): a borrower starts, lists, reads and
 * clears only their own conversations, addressed by uuid.
 */
#[CoversClass(BorrowerAssistantController::class)]
class BorrowerAssistantSessionsApiTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithPortalUsers;

    private Partner $partner;
    private User $me;
    private User $neighbour;

    protected function setUp(): void
    {
        parent::setUp();

        $this->makeHostPartner();
        $this->partner = $this->makeWhiteLabelPartner();
        $this->me = $this->makeBorrowerUser($this->partner);
        $this->neighbour = $this->makeBorrowerUser($this->partner);
    }

    private function startAs(User $user): string
    {
        $this->actingAsUser($user);

        return $this->postJson('/api/v1/borrower/assistant/sessions')->assertOk()->json('data.session_id');
    }

    #[Test]
    public function a_new_session_closes_the_open_one_and_both_are_listed(): void
    {
        $first = $this->startAs($this->me);
        $second = $this->startAs($this->me);

        $sessions = collect($this->getJson('/api/v1/borrower/assistant/sessions')->assertOk()->json('data'));
        $this->assertSame([$second, $first], $sessions->pluck('session_id')->all());
        $this->assertSame(['open', 'closed'], $sessions->pluck('status')->all());
    }

    #[Test]
    public function a_borrower_reads_their_own_conversation(): void
    {
        $mine = $this->startAs($this->me);

        $this->getJson("/api/v1/borrower/assistant/conversations/{$mine}/messages")->assertOk()->assertJsonCount(0, 'data');
    }

    #[Test]
    public function another_borrowers_conversation_is_neither_listed_nor_read(): void
    {
        $theirs = $this->startAs($this->neighbour);
        $this->startAs($this->me);

        $this->assertNotContains($theirs, collect($this->getJson('/api/v1/borrower/assistant/sessions')->json('data'))->pluck('session_id')->all());
        $this->getJson("/api/v1/borrower/assistant/conversations/{$theirs}/messages")->assertNotFound();
        $this->getJson('/api/v1/borrower/assistant/conversations/not-a-session/messages')->assertNotFound();
    }

    #[Test]
    public function clearing_history_removes_only_my_conversations(): void
    {
        $theirs = $this->startAs($this->neighbour);
        $this->startAs($this->me);
        $this->startAs($this->me);

        $this->deleteJson('/api/v1/borrower/assistant/sessions')->assertOk();

        $this->getJson('/api/v1/borrower/assistant/sessions')->assertOk()->assertJsonCount(0, 'data');
        $this->assertNotNull(ChatConversation::where('uuid', $theirs)->first());
    }
}
