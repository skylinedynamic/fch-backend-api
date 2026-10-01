<?php

namespace Tests\Feature\FundingRequest;

use App\Models\Borrower\Borrower;
use App\Models\FundingRequest\FundingRequest;
use App\Models\Partner\Partner;
use App\Models\User\User;
use App\Notifications\DynamicNotification;
use App\Services\FCH\Common\FundingRequest\FundingRequestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Laravel\Passport\Passport;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A funding request status change notifies the borrower in the borrower
 * portal and, for a non-host partner, that partner's users in the partner
 * portal - each with a link into their own portal.
 */
class FundingRequestStatusNotificationTest extends TestCase
{
    use RefreshDatabase;

    private User $hostAdmin;
    private User $borrowerUser;
    private User $partnerUser;
    private int $requestId;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Notification::fake();

        $hostId = $this->insertPartner(['is_host' => 1]);
        $partnerId = $this->insertPartner(['is_host' => 0]);

        $borrowerId = DB::table('borrowers')->insertGetId([
            'first_name' => 'Bo',
            'last_name' => 'Rower',
            'email' => 'borrower-' . uniqid() . '@example.com',
            'partner_id' => $partnerId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->hostAdmin = $this->insertUser(Partner::class, $hostId, User::PARTNER, $hostId);
        $this->partnerUser = $this->insertUser(Partner::class, $partnerId, User::PARTNER, $partnerId);
        $this->borrowerUser = $this->insertUser(Borrower::class, $borrowerId, User::BORROWER, $partnerId);

        $sequenceId = DB::table('funding_sequencings')->insertGetId([
            'name' => 'Sequence',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->requestId = DB::table('funding_requests')->insertGetId([
            'partner_id' => $partnerId,
            'borrower_id' => $borrowerId,
            'funding_sequence_id' => $sequenceId,
            'status' => FundingRequest::STATUS_PENDING,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Passport::actingAs($this->hostAdmin, [], 'api');
    }

    private function insertPartner(array $attributes): int
    {
        return DB::table('partners')->insertGetId(array_merge([
            'full_name' => 'Partner ' . uniqid(),
            'company_name' => 'Partner Co ' . uniqid(),
            'email' => 'partner-' . uniqid() . '@example.com',
            'created_at' => now(),
            'updated_at' => now(),
        ], $attributes));
    }

    private function insertUser(string $entityType, int $entityId, string $userType, int $partnerId): User
    {
        return User::findOrFail(DB::table('users')->insertGetId([
            'name' => 'User ' . uniqid(),
            'email' => 'user-' . uniqid() . '@example.com',
            'password' => bcrypt('secret'),
            'user_type' => $userType,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'partner_id' => $partnerId,
            'created_at' => now(),
            'updated_at' => now(),
        ]));
    }

    private function updateStatus(string $status): void
    {
        app(FundingRequestService::class)->fundingRequestStatusUpdate([
            'id' => $this->requestId,
            'status' => $status,
        ]);
    }

    #[Test]
    public function the_borrower_and_the_partner_are_notified_with_links_into_their_own_portal(): void
    {
        $this->updateStatus(FundingRequest::STATUS_APPROVED);

        Notification::assertSentTo($this->borrowerUser, DynamicNotification::class, function (DynamicNotification $n) {
            $data = $n->toArray($this->borrowerUser);

            return $n->typeKey === 'borrower.funding_request.status_changed'
                && $data['url'] === "/funding-requests/{$this->requestId}"
                && str_contains($data['body'], 'Approved');
        });

        Notification::assertSentTo($this->partnerUser, DynamicNotification::class, function (DynamicNotification $n) {
            return $n->typeKey === 'partner.funding_request.status_changed'
                && $n->toArray($this->partnerUser)['url'] === "/intake-review/funding-request/{$this->requestId}";
        });

        Notification::assertNotSentTo($this->hostAdmin, DynamicNotification::class);
    }

    #[Test]
    public function saving_the_same_status_again_sends_nothing(): void
    {
        $this->updateStatus(FundingRequest::STATUS_PENDING);

        Notification::assertNothingSent();
    }
}
