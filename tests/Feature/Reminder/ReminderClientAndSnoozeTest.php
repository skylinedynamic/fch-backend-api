<?php

namespace Tests\Feature\Reminder;

use App\Models\Borrower\Borrower;
use App\Models\Borrower\FundingApplication;
use App\Models\Common\Reminder;
use App\Models\Lead\Lead;
use App\Models\Partner\Partner;
use App\Models\User\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Laravel\Passport\Passport;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ReminderClientAndSnoozeTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private int $partnerId;
    private int $borrowerId;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();

        $this->partnerId = DB::table('partners')->insertGetId([
            'full_name' => 'Pat Partner',
            'company_name' => 'Partner Co',
            'email' => 'partner-' . uniqid() . '@example.com',
            'phone_number' => '(111) 111-1111',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->borrowerId = DB::table('borrowers')->insertGetId([
            'first_name' => 'Johnathan',
            'last_name' => 'Smithfield',
            'business_name' => 'Smithfield Bakery LLC',
            'email' => 'borrower-' . uniqid() . '@example.com',
            'phone_number' => '(222) 222-2222',
            'partner_id' => $this->partnerId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $userId = DB::table('users')->insertGetId([
            'name' => 'Owner User',
            'email' => 'owner-' . uniqid() . '@example.com',
            'password' => bcrypt('secret'),
            'user_type' => User::PARTNER,
            'entity_type' => Partner::class,
            'entity_id' => $this->partnerId,
            'partner_id' => $this->partnerId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->user = User::findOrFail($userId);
        Passport::actingAs($this->user, [], 'api');
    }

    private function makeReminder(string $entityType, int $entityId, array $attributes = []): Reminder
    {
        return Reminder::create(array_merge([
            'entity_id' => $entityId,
            'entity_type' => $entityType,
            'created_by' => $this->partnerId,
            'created_by_entity_type' => Partner::class,
            'reference' => 'Task',
            'summary' => 'Call about the file',
            'set_date_time' => now()->subHour(),
            'reminder_date_time' => now()->subHours(2),
            'send_at' => now()->subHours(2),
            'status' => Reminder::STATUS_SENT,
        ], $attributes));
    }

    #[Test]
    public function due_reminders_include_the_client_they_are_for(): void
    {
        $leadId = DB::table('leads')->insertGetId([
            'contact_person' => 'Lana Lead',
            'company_name' => 'Lead Holdings',
            'email' => 'lead@example.com',
            'phone_number' => '(333) 333-3333',
            'partner_id' => $this->partnerId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $applicationId = DB::table('funding_applications')->insertGetId([
            'application_number' => 'APP-TEST-1',
            'borrower_id' => $this->borrowerId,
            'partner_id' => $this->partnerId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $borrowerReminder = $this->makeReminder(Borrower::class, $this->borrowerId);
        $leadReminder = $this->makeReminder(Lead::class, $leadId);
        $partnerReminder = $this->makeReminder(Partner::class, $this->partnerId);
        $applicationReminder = $this->makeReminder(FundingApplication::class, $applicationId);

        $response = $this->getJson('/api/v1/common/reminders/due')->assertOk();
        $byId = collect($response->json('data.reminders'))->keyBy('id');

        $this->assertSame([
            'type' => 'borrower',
            'type_label' => 'Client',
            'id' => $this->borrowerId,
            'name' => 'Johnathan Smithfield',
            'business_name' => 'Smithfield Bakery LLC',
            'email' => DB::table('borrowers')->where('id', $this->borrowerId)->value('email'),
            'phone' => '(222) 222-2222',
        ], $byId[$borrowerReminder->id]['client']);
        $this->assertNull($byId[$borrowerReminder->id]['related']);

        $this->assertSame('lead', $byId[$leadReminder->id]['client']['type']);
        $this->assertSame('Lana Lead', $byId[$leadReminder->id]['client']['name']);
        $this->assertSame('Lead Holdings', $byId[$leadReminder->id]['client']['business_name']);

        $this->assertSame('partner', $byId[$partnerReminder->id]['client']['type']);
        $this->assertSame('Pat Partner', $byId[$partnerReminder->id]['client']['name']);

        // A funding application resolves to its borrower, with the application as `related`
        $this->assertSame('borrower', $byId[$applicationReminder->id]['client']['type']);
        $this->assertSame($this->borrowerId, $byId[$applicationReminder->id]['client']['id']);
        $this->assertSame([
            'type' => 'funding_application',
            'id' => $applicationId,
            'label' => 'Funding Application APP-TEST-1',
        ], $byId[$applicationReminder->id]['related']);
    }

    #[Test]
    public function reminders_can_be_searched_by_client_name(): void
    {
        $match = $this->makeReminder(Borrower::class, $this->borrowerId, ['summary' => 'Unrelated subject']);
        $other = $this->makeReminder(Partner::class, $this->partnerId, ['summary' => 'Another subject']);

        $ids = collect($this->getJson('/api/v1/common/reminders?search=' . urlencode('Johnathan Smithfield'))
            ->assertOk()
            ->json('data.reminders.data'))->pluck('id');

        $this->assertTrue($ids->contains($match->id));
        $this->assertFalse($ids->contains($other->id));
    }

    #[Test]
    public function snoozing_with_a_note_records_it_in_the_history(): void
    {
        $reminder = $this->makeReminder(Borrower::class, $this->borrowerId);

        $this->travelTo(now()->startOfMinute());

        $response = $this->postJson("/api/v1/common/reminders/{$reminder->id}/snooze", [
            'minutes' => 25,
            'note' => '  Client asked me to call back after lunch  ',
        ])->assertOk();

        $reminder->refresh();
        $this->assertSame(Reminder::STATUS_PENDING, $reminder->status);
        $this->assertTrue($reminder->reminder_date_time->equalTo(now()->addMinutes(25)));
        $this->assertCount(1, $reminder->snooze_history);
        $this->assertSame('snoozed', $reminder->snooze_history[0]['action']);
        $this->assertSame(25, $reminder->snooze_history[0]['minutes']);
        $this->assertSame('Client asked me to call back after lunch', $reminder->snooze_history[0]['note']);
        $this->assertSame('Owner User', $reminder->snooze_history[0]['snoozed_by']);

        $response->assertJsonPath('data.reminder.last_snooze_note', 'Client asked me to call back after lunch');
        $response->assertJsonPath('data.reminder.client.name', 'Johnathan Smithfield');

        // A second snooze without a note keeps the earlier note as the latest one
        $this->postJson("/api/v1/common/reminders/{$reminder->id}/snooze", ['minutes' => 5])
            ->assertOk()
            ->assertJsonPath('data.reminder.last_snooze_note', 'Client asked me to call back after lunch')
            ->assertJsonCount(2, 'data.reminder.snooze_history');
    }

    #[Test]
    public function a_blank_note_is_stored_as_no_note(): void
    {
        $reminder = $this->makeReminder(Borrower::class, $this->borrowerId);

        $this->postJson("/api/v1/common/reminders/{$reminder->id}/snooze", ['minutes' => 10, 'note' => '   '])
            ->assertOk();

        $this->assertNull($reminder->refresh()->snooze_history[0]['note']);
    }

    #[Test]
    public function snooze_note_and_minutes_are_validated(): void
    {
        $reminder = $this->makeReminder(Borrower::class, $this->borrowerId);
        $url = "/api/v1/common/reminders/{$reminder->id}/snooze";

        $this->postJson($url, ['minutes' => 10, 'note' => str_repeat('a', 201)])
            ->assertStatus(422)
            ->assertJsonPath('errors.note', 'The snooze note may not be greater than 200 characters.');

        $this->postJson($url, ['minutes' => 10, 'note' => ['not', 'a', 'string']])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['note']]);

        $this->postJson($url, ['minutes' => 0])
            ->assertStatus(422)
            ->assertJsonPath('errors.minutes', 'The minutes must be at least 1.');

        $this->postJson($url, ['minutes' => 1441])
            ->assertStatus(422)
            ->assertJsonPath('errors.minutes', 'The snooze may not be longer than 1440 minutes (24 hours).');

        $this->postJson($url, ['minutes' => 'abc'])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['minutes']]);

        $this->postJson($url, ['note' => 'no minutes'])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['minutes']]);

        // Nothing was recorded by the rejected requests
        $this->assertNull($reminder->refresh()->snooze_history);

        // A note right on the 200-character limit is accepted and kept whole.
        $this->postJson($url, ['minutes' => 1440, 'note' => str_repeat('a', 200)])->assertOk();
        $this->assertSame(200, strlen($reminder->refresh()->snooze_history[0]['note']));
    }

    #[Test]
    public function another_users_reminder_cannot_be_snoozed(): void
    {
        $otherPartnerId = DB::table('partners')->insertGetId([
            'full_name' => 'Other Partner',
            'email' => 'other-' . uniqid() . '@example.com',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $reminder = $this->makeReminder(Borrower::class, $this->borrowerId, ['created_by' => $otherPartnerId]);

        $this->postJson("/api/v1/common/reminders/{$reminder->id}/snooze", ['minutes' => 10, 'note' => 'x'])
            ->assertStatus(404);

        $this->assertNull($reminder->refresh()->snooze_history);
    }

    #[Test]
    public function a_reminder_created_from_an_activity_is_linked_to_it_for_editing(): void
    {
        $response = $this->postJson('/api/v1/common/activity-logs', [
            'log_type' => 'activity',
            'borrower_id' => $this->borrowerId,
            'reference' => 'Task',
            'subject' => 'Follow up on documents',
            'description' => 'Ask for bank statements',
            'activity_date' => now()->addDay()->format('Y-m-d H:i:s'),
            'remind_me' => '1',
            'remind_at' => now()->addDay()->subMinutes(30)->format('Y-m-d H:i:s'),
            'visibility' => 'partner',
        ])->assertSuccessful();

        $logId = $response->json('data.id');
        $reminder = Reminder::where('activity_log_id', $logId)->first();

        $this->assertNotNull($reminder, 'Reminder should store the activity log it was created from');
        $this->assertSame('Johnathan Smithfield', $reminder->loadClient()->clientSummary()['client']['name']);

        $this->getJson('/api/v1/common/reminders?per_page=50')
            ->assertOk()
            ->assertJsonFragment(['id' => $reminder->id, 'activity_log_id' => $logId]);
    }
}
