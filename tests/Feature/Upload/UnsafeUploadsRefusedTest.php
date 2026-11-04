<?php

namespace Tests\Feature\Upload;

use App\Http\Requests\Api\V1\FCH\Common\ActivityLog\StoreActivityLogRequest;
use App\Http\Requests\Api\V1\Global\FileManager\UploadFileRequest;
use App\Models\Partner\Partner;
use App\Models\User\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/**
 * BUG-015 (QA round 2): a borrower uploaded an SVG with a <script> through the
 * file manager (Documents) and it ran when opened from /storage. Only the
 * portals checked file types; called directly, the API took any file. The
 * upload endpoints now refuse SVG and HTML themselves.
 */
#[CoversClass(UploadFileRequest::class)]
#[CoversClass(StoreActivityLogRequest::class)]
class UnsafeUploadsRefusedTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithPortalUsers;

    private const SCRIPTED_SVG = '<svg xmlns="http://www.w3.org/2000/svg"><script>console.log("svg-xss")</script></svg>';

    private Partner $partner;
    private User $borrower;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        $this->makeHostPartner();
        $this->partner = $this->makeWhiteLabelPartner();
        $this->borrower = $this->actingAsUser($this->makeBorrowerUser($this->partner));
    }

    private function upload(UploadedFile $file)
    {
        return $this->postJson('/api/v1/filemanager/upload', [
            'entity_type' => 'borrowers',
            'entity_id' => $this->borrower->entity_id,
            'path' => '/',
            'file' => $file,
        ]);
    }

    #[Test]
    public function the_file_manager_refuses_an_svg_with_a_script(): void
    {
        $this->upload(UploadedFile::fake()->createWithContent('qa-r2-logo.svg', self::SCRIPTED_SVG))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['file']);

        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    #[Test]
    public function the_file_manager_refuses_html_even_named_as_an_image(): void
    {
        $this->upload(UploadedFile::fake()->createWithContent('statement.jpg', '<html><script>alert(1)</script></html>'))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['file']);
    }

    #[Test]
    public function the_file_manager_still_takes_a_document(): void
    {
        $this->upload(UploadedFile::fake()->create('bank statement.pdf', 20, 'application/pdf'))->assertOk();
    }

    #[Test]
    public function an_activity_attachment_may_not_be_an_svg(): void
    {
        $this->actingAsUser($this->makePartnerAdmin($this->partner));

        $this->postJson('/api/v1/common/activity-logs', [
            'attachments' => [UploadedFile::fake()->createWithContent('diagram.svg', self::SCRIPTED_SVG)],
        ])->assertUnprocessable()->assertJsonValidationErrors(['attachments.0']);
    }
}
