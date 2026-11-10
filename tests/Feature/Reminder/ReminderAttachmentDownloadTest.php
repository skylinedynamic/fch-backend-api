<?php

namespace Tests\Feature\Reminder;

use App\Http\Controllers\Api\V1\FCH\Common\Reminder\ReminderController;
use App\Models\Common\Reminder;
use App\Models\User\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;
use Illuminate\Support\Facades\Storage;

/**
 * GET /common/reminders/{id}/attachments/download?path=... - a file attached to
 * a reminder, for the user who set the reminder, and only a file that really
 * is attached to it.
 */
#[CoversClass(ReminderController::class)]
class ReminderAttachmentDownloadTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithPortalUsers;

    private User $owner;
    private string $relative;
    private string $storage;

    protected function setUp(): void
    {
        parent::setUp();

        $partner = $this->makeCertifiedPartner();
        $this->owner = $this->makePartnerAdmin($partner);

        // A throwaway storage folder rather than the real one (see findings F49).
        $this->storage = sys_get_temp_dir() . '/fch-storage-' . uniqid();
        foreach (['app/public/attachments', 'framework/views', 'framework/cache/data', 'framework/sessions', 'logs'] as $dir) {
            File::ensureDirectoryExists("{$this->storage}/{$dir}");
        }
        $this->copyPassportKeys(storage_path(), $this->storage);
        $this->app->useStoragePath($this->storage);

        $this->relative = 'attachments/reminder-' . uniqid() . '.txt';
        // Through the public disk, which is where uploads put it and the
        // download reads it from (findings O16).
        Storage::disk('uploads')->put($this->relative, 'call notes');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->storage);

        parent::tearDown();
    }

    private function reminderOf(User $user, array $attachments): Reminder
    {
        return Reminder::create([
            'entity_id' => $user->id,
            'entity_type' => User::class,
            'created_by' => $user->id,
            'created_by_entity_type' => User::class,
            'reference' => 'Task',
            'summary' => 'Call back',
            'set_date_time' => now(),
            'reminder_date_time' => now()->addDay(),
            'send_at' => now()->addDay(),
            'status' => Reminder::STATUS_PENDING,
            'attachments' => $attachments,
        ]);
    }

    private function download(Reminder $reminder, string $path)
    {
        return $this->get("/api/v1/common/reminders/{$reminder->id}/attachments/download?path=" . urlencode($path));
    }

    #[Test]
    public function the_reminders_owner_downloads_its_attachment_under_its_name(): void
    {
        $reminder = $this->reminderOf($this->owner, [['path' => $this->relative, 'name' => 'Call notes.txt']]);
        $this->actingAsUser($this->owner);

        $response = $this->download($reminder, $this->relative)->assertOk();

        $this->assertStringContainsString('Call notes.txt', $response->headers->get('Content-Disposition'));
    }

    #[Test]
    public function someone_elses_reminder_is_not_found(): void
    {
        $reminder = $this->reminderOf($this->owner, [['path' => $this->relative, 'name' => 'Call notes.txt']]);
        $this->actingAsUser($this->makePartnerAdmin($this->makeCertifiedPartner()));

        $this->getJson("/api/v1/common/reminders/{$reminder->id}/attachments/download?path=" . urlencode($this->relative))
            ->assertNotFound();
    }

    #[Test]
    public function only_a_file_attached_to_the_reminder_can_be_fetched_through_it(): void
    {
        $reminder = $this->reminderOf($this->owner, []);
        $this->actingAsUser($this->owner);

        $this->getJson("/api/v1/common/reminders/{$reminder->id}/attachments/download?path=" . urlencode($this->relative))
            ->assertNotFound();
    }
}
