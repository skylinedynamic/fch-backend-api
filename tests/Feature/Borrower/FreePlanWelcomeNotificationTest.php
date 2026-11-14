<?php

namespace Tests\Feature\Borrower;

use App\Models\Borrower\Borrower;
use App\Models\Partner\Partner;
use App\Models\User\User;
use App\Notifications\DynamicNotification;
use App\Services\FCH\Common\Borrower\BorrowerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/**
 * BUG-143: a client created on the Limited Free Plan with no end date (every
 * embed form conversion, or Add Client with no date) was sent the free-trial
 * bell "Your free trial is active until N/A." A plan with no end date gets a
 * free-plan welcome instead; one with an end date still names that date.
 */
#[CoversClass(BorrowerService::class)]
class FreePlanWelcomeNotificationTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithPortalUsers;

    private Partner $partner;
    private User $partnerAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Notification::fake();
        $this->makeHostPartner();
        $this->partner = $this->makeCertifiedPartner();
        $this->partnerAdmin = $this->makePartnerAdmin($this->partner);
    }

    private function createFreePlanClient(?string $endDate): User
    {
        $this->actingAsUser($this->partnerAdmin);

        $id = $this->postJson('/api/v1/common/borrowers', [
            'first_name' => 'Wanda',
            'last_name' => 'Embed',
            'email' => 'wanda-' . uniqid() . '@example.test',
            'phone_number' => '(555) 234-5678',
            'country' => 'United States',
            'business_country' => 'United States',
            'is_free_trail' => true,
            'free_trail_end_date' => $endDate,
        ])->assertCreated()->json('data.id');

        return User::where('entity_type', Borrower::class)->where('entity_id', $id)->firstOrFail();
    }

    /** @return array<int, DynamicNotification> */
    private function bellsFor(User $user): array
    {
        return Notification::sent($user, DynamicNotification::class)->all();
    }

    #[Test]
    public function a_free_plan_with_no_end_date_gets_a_free_plan_welcome_not_a_trial_until_na(): void
    {
        $client = $this->createFreePlanClient(null);

        $bells = $this->bellsFor($client);
        $this->assertCount(1, $bells);
        $this->assertSame('borrower.account.free_plan_started', $bells[0]->typeKey);
        $this->assertStringContainsString('Free Plan', $bells[0]->message->title);
        $this->assertStringNotContainsString('N/A', $bells[0]->message->body);
        $this->assertStringNotContainsStringIgnoringCase('trial', $bells[0]->message->title . ' ' . $bells[0]->message->body);
    }

    /**
     * A client's free plan has no end date (decision 2026-11-15): one sent
     * through the API is not kept, so the welcome is the free plan one.
     */
    #[Test]
    public function an_end_date_sent_for_a_free_plan_is_ignored_and_the_free_plan_welcome_is_sent(): void
    {
        $client = $this->createFreePlanClient(now()->addDays(14)->toDateString());

        $bells = $this->bellsFor($client);
        $this->assertCount(1, $bells);
        $this->assertSame('borrower.account.free_plan_started', $bells[0]->typeKey);
        $this->assertStringNotContainsStringIgnoringCase('trial', $bells[0]->message->title . ' ' . $bells[0]->message->body);
    }
}
