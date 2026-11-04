<?php

namespace Tests\Feature\CreditProfile;

use App\Http\Controllers\Api\V1\FCH\Common\Borrower\BorrowerController;
use App\Models\ActivityLog\ActivityLog;
use App\Models\Borrower\Borrower;
use App\Models\User\User;
use App\Services\Borrower\CreditProfile\ClientCreditProfileService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/**
 * Defect (BUG-075): saving a client's credit profile, or removing it, left no
 * entry in the client's Activity panel - the only trace of who changed a
 * client's consumer report data, and when, was the application log.
 *
 * Both entries are internal: credit report data is for the host's team only.
 */
#[CoversClass(BorrowerController::class)]
#[CoversClass(ClientCreditProfileService::class)]
class CreditProfileActivityTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithPortalUsers;

    private Borrower $borrower;
    private User $hostAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        // Never the real public disk, nor real mail.
        Storage::fake('public');
        Mail::fake();

        $this->hostAdmin = $this->makePartnerAdmin($this->makeHostPartner(), [], ['name' => 'Hana Host']);
        $this->borrower = $this->makeBorrower($this->makeWhiteLabelPartner());
    }

    private function save(array $metrics = ['fico_score' => 712, 'paydex_score' => 80]): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/v1/common/borrowers/save-credit-profile', [
            'borrower_id' => $this->borrower->id,
            'metrics' => $metrics,
            'personal_info' => ['name' => 'Bo Rower'],
        ]);
    }

    /** The client's Activity panel, as the host's team reads it. */
    private function activity(): array
    {
        return $this->postJson('/api/v1/common/activity-logs/fetch-all-activities', [
            'borrower_id' => $this->borrower->id,
            'limit' => 50,
        ])->assertOk()->json('data');
    }

    private function entries(string $logType): array
    {
        return array_values(array_filter($this->activity(), fn ($entry) => $entry['log_type_raw'] === $logType));
    }

    #[Test]
    public function each_save_is_recorded_in_the_clients_activity_with_who_saved_it(): void
    {
        $this->actingAsUser($this->hostAdmin);

        $this->save()->assertOk();
        $this->save(['fico_score' => 730])->assertOk();

        $saved = $this->entries(ActivityLog::LOG_TYPE_CREDIT_PROFILE_SAVE);

        $this->assertCount(2, $saved);
        $this->assertSame(__('borrower.activity.credit_profile_saved'), $saved[0]['subject']);
        $this->assertSame('Hana Host', $saved[0]['causer_name']);
        $this->assertSame(ActivityLog::VISIBILITY_INTERNAL, $saved[0]['visibility']);
    }

    #[Test]
    public function removing_the_credit_profile_is_recorded_in_the_clients_activity(): void
    {
        $this->actingAsUser($this->hostAdmin);
        $this->save()->assertOk();

        $this->deleteJson("/api/v1/common/borrowers/{$this->borrower->id}/credit-profile")->assertOk();

        $removed = $this->entries(ActivityLog::LOG_TYPE_CREDIT_PROFILE_DELETE);

        $this->assertCount(1, $removed);
        $this->assertSame(__('borrower.activity.credit_profile_removed'), $removed[0]['subject']);
        $this->assertSame('Hana Host', $removed[0]['causer_name']);
        $this->assertSame(ActivityLog::VISIBILITY_INTERNAL, $removed[0]['visibility']);
    }

    #[Test]
    public function a_refused_save_or_a_remove_with_nothing_to_remove_records_nothing(): void
    {
        $this->actingAsUser($this->hostAdmin);

        $this->save(['fico_score' => ''])->assertStatus(422);
        $this->deleteJson("/api/v1/common/borrowers/{$this->borrower->id}/credit-profile")->assertNotFound();

        $this->assertSame([], $this->entries(ActivityLog::LOG_TYPE_CREDIT_PROFILE_SAVE));
        $this->assertSame([], $this->entries(ActivityLog::LOG_TYPE_CREDIT_PROFILE_DELETE));
    }

    #[Test]
    public function an_activity_entry_that_will_not_write_does_not_fail_the_save_or_the_removal(): void
    {
        $this->mock(\App\Services\FCH\Common\ActivityLog\ActivityLogService::class)
            ->shouldReceive('logModelEvent')->andThrow(new \RuntimeException('activity table down'));
        $this->actingAsUser($this->hostAdmin);

        $this->save()->assertOk();
        $this->assertSame(712, (int) $this->borrower->fresh()->fico_score);

        $this->deleteJson("/api/v1/common/borrowers/{$this->borrower->id}/credit-profile")->assertOk();
        $this->assertNull($this->borrower->fresh()->creditProfile);
    }

    #[Test]
    public function the_client_does_not_see_these_entries(): void
    {
        $this->actingAsUser($this->hostAdmin);
        $this->save()->assertOk();
        $this->deleteJson("/api/v1/common/borrowers/{$this->borrower->id}/credit-profile")->assertOk();

        $client = $this->makeUser([
            'user_type' => User::BORROWER,
            'entity_type' => Borrower::class,
            'entity_id' => $this->borrower->id,
            'partner_id' => $this->borrower->partner_id,
        ]);
        $this->actingAsUser($client);

        $seen = collect($this->postJson('/api/v1/borrower/activity-logs/fetch-all-activities', [
            'borrower_id' => $this->borrower->id,
            'limit' => 50,
        ])->assertOk()->json('data'))->pluck('log_type_raw')->all();

        $this->assertNotContains(ActivityLog::LOG_TYPE_CREDIT_PROFILE_SAVE, $seen);
        $this->assertNotContains(ActivityLog::LOG_TYPE_CREDIT_PROFILE_DELETE, $seen);
    }
}
