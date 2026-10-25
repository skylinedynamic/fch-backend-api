<?php

namespace Tests\Feature\Application;

use App\Http\Controllers\Api\V1\FCH\Common\Application\RequestApplicationController;
use App\Models\Borrower\Borrower;
use App\Models\Borrower\PreQualificationRequest;
use App\Models\Partner\Partner;
use App\Models\User\User;
use App\Notifications\DynamicNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/**
 * BUG-063: the owner moving a pre-qualification Pending -> In Review ->
 * Approved told the client nothing - the status update saved and returned.
 * Each real status change now puts a bell notification in the borrower
 * portal; saving the same status again does not.
 */
#[CoversClass(RequestApplicationController::class)]
class PreQualificationStatusNotificationTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithPortalUsers;

    private Partner $partner;
    private User $borrowerUser;
    private int $preQualId;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Notification::fake();
        $this->makeHostPartner();
        $this->partner = $this->makeCertifiedPartner();
        $this->borrowerUser = $this->makeBorrowerUser($this->partner);
        $this->preQualId = DB::table('pre_qualification_requests')->insertGetId([
            'borrower_id' => $this->borrowerUser->entity_id,
            'partner_id' => $this->partner->id,
            'status' => PreQualificationRequest::PENDING,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->actingAsUser($this->makePartnerAdmin($this->partner));
    }

    private function updateStatus(string $status, ?string $note = null)
    {
        return $this->postJson('/api/v1/common/request-application/pre-qualification/status-update', array_filter([
            'id' => $this->preQualId,
            'status' => $status,
            'status_note' => $note,
        ]))->assertOk();
    }

    /** @return array<int, array<string, mixed>> the borrower's bell items, oldest first */
    private function bell(): array
    {
        return Notification::sent($this->borrowerUser, DynamicNotification::class)
            ->filter(fn (DynamicNotification $n) => $n->typeKey === 'borrower.pre_qualification.status_changed')
            ->map(fn (DynamicNotification $n) => $n->toArray($this->borrowerUser))
            ->values()
            ->all();
    }

    #[Test]
    public function each_status_change_reaches_the_clients_bell(): void
    {
        $this->updateStatus(PreQualificationRequest::IN_REVIEW);
        $this->updateStatus(PreQualificationRequest::APPROVED);

        $bell = $this->bell();
        $this->assertCount(2, $bell);
        $this->assertSame('Pre-qualification In Review', $bell[0]['title']);
        $this->assertSame('Your pre-qualification is now In Review.', $bell[0]['body']);
        $this->assertSame('Pre-qualification Approved', $bell[1]['title']);
        $this->assertSame('/pre-qualification', $bell[1]['url']);
    }

    #[Test]
    public function the_note_goes_with_it(): void
    {
        $this->updateStatus(PreQualificationRequest::DECLINED, 'Revenue below the minimum.');

        $this->assertSame('Your pre-qualification is now Declined. Note: Revenue below the minimum.', $this->bell()[0]['body']);
    }

    #[Test]
    public function saving_the_same_status_again_sends_nothing(): void
    {
        $this->updateStatus(PreQualificationRequest::PENDING);

        $this->assertSame([], $this->bell());
    }

    #[Test]
    public function only_the_borrower_is_notified_not_the_partner(): void
    {
        $this->updateStatus(PreQualificationRequest::APPROVED);

        $partnerUsers = User::where('partner_id', $this->partner->id)->where('entity_type', '!=', Borrower::class)->get();
        foreach ($partnerUsers as $user) {
            Notification::assertNotSentTo($user, DynamicNotification::class, fn (DynamicNotification $n) => $n->typeKey === 'borrower.pre_qualification.status_changed');
        }
        $this->assertCount(1, $this->bell());
    }
}
