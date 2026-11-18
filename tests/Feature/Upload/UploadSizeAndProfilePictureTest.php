<?php

namespace Tests\Feature\Upload;

use App\Http\Requests\Api\V1\FCH\Common\AuthProfile\UpdateProfilePictureRequest;
use App\Http\Requests\Api\V1\Global\FileManager\UploadFileRequest;
use App\Models\Partner\Partner;
use App\Models\User\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use App\Support\Files\Folder;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/**
 * BUG-014 (QA round 2): as host admin, Profile > camera > Upload took a 12 MB
 * qa-12mb.png - the PNG signature and then zeros - with 200, and it could then
 * be picked as the profile picture. The file manager had no size limit and did
 * not check that a picture was one; the profile picture was any path string.
 */
#[CoversClass(UploadFileRequest::class)]
#[CoversClass(UpdateProfilePictureRequest::class)]
class UploadSizeAndProfilePictureTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithPortalUsers;

    private const PNG_MAGIC = "\x89PNG\r\n\x1a\n";

    private Partner $partner;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        $this->makeHostPartner();
        $this->partner = $this->makeWhiteLabelPartner();
        $this->admin = $this->actingAsUser($this->makePartnerAdmin($this->partner));
    }

    private function fakePng(string $name, int $kilobytes): UploadedFile
    {
        return UploadedFile::fake()->createWithContent(
            $name,
            self::PNG_MAGIC . str_repeat("\0", $kilobytes * 1024 - strlen(self::PNG_MAGIC))
        );
    }

    /** Into the partner's own folder (public: pictures only), or a client's (private: documents too). */
    private function upload(UploadedFile $file, bool $private = false)
    {
        return $this->postJson('/api/v1/filemanager/upload', [
            'entity_type' => $private ? Folder::BORROWERS : Folder::PARTNERS,
            'entity_id' => $private ? $this->makeBorrower($this->partner)->id : $this->partner->id,
            'path' => '/',
            'file' => $file,
        ]);
    }

    private function setPicture(string $path)
    {
        return $this->postJson('/api/v1/common/auth-profile/update-profile-picture', ['image' => $path]);
    }

    /** The bytes of a real PNG. (The fake's temp file goes with the object, so hold it while reading.) */
    private function pngBytes(int $size = 120): string
    {
        $image = UploadedFile::fake()->image('x.png', $size, $size);

        return file_get_contents($image->getRealPath());
    }

    /** A file already in this partner's file manager folder. */
    private function stored(string $name, string $content): string
    {
        Storage::disk('public')->put("partners/{$this->partner->id}/{$name}", $content);

        return "/{$name}";
    }

    #[Test]
    public function qa_12_mb_fake_png_is_refused_by_the_file_manager(): void
    {
        $this->upload($this->fakePng('qa-12mb.png', 12 * 1024))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['file' => __('uploads.too_large', ['size' => 5])]);

        $this->assertSame([], Storage::disk('public')->allFiles("partners/{$this->partner->id}"));
    }

    #[Test]
    public function a_small_fake_png_is_refused_as_not_an_image(): void
    {
        $this->upload($this->fakePng('qa-small.png', 50))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['file' => __('uploads.invalid_image')]);
    }

    #[Test]
    public function a_document_over_20_mb_is_refused_and_a_real_image_is_stored(): void
    {
        $this->upload(UploadedFile::fake()->create('statement.pdf', 20 * 1024 + 1, 'application/pdf'), private: true)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['file' => __('uploads.too_large', ['size' => 20])]);

        $this->upload(UploadedFile::fake()->image('avatar.png', 200, 200))->assertOk();
    }

    #[Test]
    public function the_profile_picture_must_be_a_real_image_in_the_users_folder(): void
    {
        $fake = $this->stored('qa-small.png', self::PNG_MAGIC . str_repeat("\0", 1024));
        $this->setPicture($fake)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['image' => __('uploads.invalid_image')]);

        $pdf = $this->stored('statement.pdf', '%PDF-1.7 ...');
        $this->setPicture($pdf)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['image' => __('uploads.picture_type')]);

        $this->setPicture('/not-there.png')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['image' => __('uploads.picture_missing')]);

        $this->assertNull($this->admin->fresh()->getRawOriginal('image'));
    }

    #[Test]
    public function the_profile_picture_may_be_up_to_5_mb(): void
    {
        // Larger than 5 MB on disk, whatever is in it.
        $big = $this->stored('big.png', $this->pngBytes(10) . str_repeat("\0", 5 * 1024 * 1024));
        $this->setPicture($big)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['image' => __('uploads.too_large', ['size' => 5])]);
    }

    #[Test]
    public function a_real_image_in_the_users_folder_becomes_the_profile_picture(): void
    {
        $path = $this->stored('me.png', $this->pngBytes());

        $this->setPicture($path)->assertOk();

        $this->assertSame("partners/{$this->partner->id}/me.png", $this->admin->fresh()->getRawOriginal('image'));
    }

    #[Test]
    public function a_path_out_of_the_users_folder_is_refused(): void
    {
        $other = $this->makeWhiteLabelPartner();
        Storage::disk('public')->put("partners/{$other->id}/theirs.png", $this->pngBytes());

        $this->setPicture("/../{$other->id}/theirs.png")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['image']);
    }
}
