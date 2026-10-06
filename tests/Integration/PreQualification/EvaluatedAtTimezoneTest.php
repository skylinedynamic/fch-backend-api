<?php

namespace Tests\Integration\PreQualification;

use App\Http\Resources\Borrower\PreQualification\PreQualificationResource;
use App\Models\Borrower\PreQualificationRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The "Evaluated" time on the underwriting result.
 *
 * Every timestamp in these responses is converted to the viewer's timezone from
 * the X-Timezone header. gpt_generated_at was the one exception — it was
 * formatted straight off the stored UTC value — so "Evaluated" read hours away
 * from the Submitted date beside it and from the clock on the viewer's own
 * machine.
 */
class EvaluatedAtTimezoneTest extends TestCase
{
    use RefreshDatabase;

    private function borrowerPayload(string $timezone): array
    {
        $application = PreQualificationRequest::create([
            'business_name' => 'Alpha Tech LLC',
            // Stored in UTC, as every timestamp in this application is.
            'gpt_generated_at' => '2026-10-05 15:57:00',
            'created_at' => '2026-10-05 15:57:00',
        ]);

        // The header the front-end sends on every request.
        app()->instance('request', Request::create('/', 'GET', server: ['HTTP_X_TIMEZONE' => $timezone]));

        return (new PreQualificationResource($application->fresh()))->toArray(request());
    }

    #[Test]
    public function the_evaluated_time_is_shown_in_the_viewers_timezone(): void
    {
        // 15:57 UTC is 11:57 in New York on this date.
        $this->assertSame('2026-10-05 11:57 AM', $this->borrowerPayload('America/New_York')['gpt_generated_at']);
    }

    #[Test]
    public function the_same_moment_reads_correctly_in_another_timezone(): void
    {
        // 15:57 UTC is 21:57 in Dhaka.
        $this->assertSame('2026-10-05 09:57 PM', $this->borrowerPayload('Asia/Dhaka')['gpt_generated_at']);
    }

    #[Test]
    public function an_application_that_was_never_evaluated_has_no_time_to_show(): void
    {
        $application = PreQualificationRequest::create(['business_name' => 'Alpha Tech LLC']);

        app()->instance('request', Request::create('/', 'GET', server: ['HTTP_X_TIMEZONE' => 'Asia/Dhaka']));

        $this->assertNull((new PreQualificationResource($application))->toArray(request())['gpt_generated_at']);
    }
}
