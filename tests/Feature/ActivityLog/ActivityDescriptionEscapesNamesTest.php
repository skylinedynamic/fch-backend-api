<?php

namespace Tests\Feature\ActivityLog;

use App\Models\ActivityLog\ActivityLog;
use App\Models\Lead\Lead;
use App\Services\FCH\Common\ActivityLog\ActivityLogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/**
 * BUG-004 (QA round 2): a lead named `R2-XSS <img src=x onerror=...>` - the
 * name went into "Lead profile created for ..." as is, and the portals render
 * descriptions as HTML, so the onerror ran. A name written into a system
 * description is now escaped: it reads the same, and is text, not markup.
 */
#[CoversClass(ActivityLogService::class)]
class ActivityDescriptionEscapesNamesTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithPortalUsers;

    private const NAME = 'R2-XSS <img src=x onerror="console.log(1)">';

    #[Test]
    public function a_lead_name_with_html_is_escaped_in_its_created_entry(): void
    {
        Mail::fake();
        $this->makeHostPartner();
        $partner = $this->makeCertifiedPartner();
        $this->actingAsUser($this->makePartnerAdmin($partner));

        $id = $this->postJson('/api/v1/common/leads/details', [
            'probability' => 'High',
            'lead_type' => 'Borrower',
            'status' => 'New',
            'company_name' => self::NAME,
            'contact_person' => 'Xavier Test',
            'lead_source' => 'Website',
        ])->assertSuccessful()->json('data.id');

        $descriptions = ActivityLog::where('subject_type', Lead::class)->where('subject_id', $id)->pluck('description');

        $this->assertNotEmpty($descriptions);
        foreach ($descriptions as $description) {
            $this->assertStringNotContainsString('<img', $description);
        }
        $this->assertTrue(
            $descriptions->contains(fn ($d) => str_contains($d, e(self::NAME))),
            'The name should still be there, escaped: ' . $descriptions->implode(' | ')
        );
    }
}
