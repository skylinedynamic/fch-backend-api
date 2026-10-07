<?php

namespace Tests\Feature\ActivityLog;

use App\Mail\ActivityLog\ActivityLogMail;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * An activity-log email is queued, so it has to carry its attachments with it.
 *
 * It used to attach the request's UploadedFile objects at send time. Those are
 * PHP temp files deleted when the request ends - long before a worker runs -
 * and an UploadedFile cannot be serialized into a job at all. The file
 * contents are therefore captured when the mail is built.
 */
#[CoversClass(ActivityLogMail::class)]
class ActivityLogMailQueueTest extends TestCase
{
    use RefreshDatabase;

    private function mail(): ActivityLogMail
    {
        return new ActivityLogMail(
            ['subject' => 'Statement', 'body' => '<p>Attached.</p>'],
            ['statement.pdf'],
            [UploadedFile::fake()->createWithContent('statement.pdf', "%PDF-1.4\x00\xff binary")]
        );
    }

    #[Test]
    public function it_is_queued(): void
    {
        $this->assertInstanceOf(ShouldQueue::class, $this->mail());
    }

    #[Test]
    public function its_attachments_survive_the_trip_through_the_queue(): void
    {
        // json_encode is what the queue does to the payload; binary file
        // contents in a serialized string would make it fail.
        $payload = json_encode(['data' => serialize($this->mail())]);
        $this->assertNotFalse($payload, json_last_error_msg());

        $restored = unserialize(json_decode($payload, true)['data']);
        $attachments = $restored->attachments();

        $this->assertCount(1, $attachments);
        $this->assertSame('statement.pdf', $attachments[0]->as);
        $this->assertSame("%PDF-1.4\x00\xff binary", $attachments[0]->attachWith(
            fn () => $this->fail('the attachment must not point at a (long gone) temp file'),
            fn (\Closure $data) => $data(),
        ));
    }
}
