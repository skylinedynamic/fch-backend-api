<?php

namespace Tests\Feature\Files;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Illuminate\Contracts\Filesystem\Filesystem;

/**
 * The `uploads` disk (BUG-016): the one disk the app keeps uploads on. It sends
 * a path to the private disk when its first folder is listed in
 * config/files.php private_folders, and to the public disk otherwise - so a
 * folder turns private by being listed, with no call site to change. Its url()
 * is a short-lived signed URL for a private file and the plain /storage URL for
 * a public one.
 */
class UploadsDiskTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['files.private_folders' => ['secret' => config('files.private_disk')]]);
    }

    private function uploads(): Filesystem
    {
        return Storage::disk('uploads');
    }

    #[Test]
    public function a_file_in_a_private_folder_is_written_to_the_private_disk_only(): void
    {
        $this->uploads()->put('secret/1/a.pdf', '%PDF secret');

        $this->assertSame('%PDF secret', Storage::disk(config('files.private_disk'))->get('secret/1/a.pdf'));
        $this->assertFalse(Storage::disk('public')->exists('secret/1/a.pdf'));
        $this->assertSame('%PDF secret', $this->uploads()->get('secret/1/a.pdf'));
    }

    #[Test]
    public function a_file_in_any_other_folder_stays_on_the_public_disk(): void
    {
        $this->uploads()->put('logos/host.png', 'png');

        $this->assertTrue(Storage::disk('public')->exists('logos/host.png'));
        $this->assertFalse(Storage::disk(config('files.private_disk'))->exists('logos/host.png'));
    }

    #[Test]
    public function a_private_file_gets_a_signed_url_and_a_public_one_its_storage_url(): void
    {
        $this->uploads()->put('secret/1/a.pdf', '%PDF secret');
        $this->uploads()->put('logos/host.png', 'png');

        $private = $this->uploads()->url('secret/1/a.pdf');
        $this->assertStringContainsString('/private-files/secret/1/a.pdf', $private);
        $this->assertStringContainsString('signature=', $private);
        $this->get($private)->assertOk()->assertStreamedContent('%PDF secret');

        $this->assertStringContainsString('/storage/logos/host.png', $this->uploads()->url('logos/host.png'));
    }

    #[Test]
    public function path_points_at_the_disk_the_file_is_on(): void
    {
        $this->uploads()->put('secret/1/a.pdf', '%PDF secret');

        $this->assertFileExists($this->uploads()->path('secret/1/a.pdf'));
        $this->assertSame(Storage::disk(config('files.private_disk'))->path('secret/1/a.pdf'), $this->uploads()->path('secret/1/a.pdf'));
    }

    #[Test]
    public function the_usual_operations_work_on_a_private_folder(): void
    {
        $disk = $this->uploads();
        $stored = $disk->putFileAs('secret/1', UploadedFile::fake()->createWithContent('bank.pdf', '%PDF bank'), 'bank.pdf');

        $this->assertSame('secret/1/bank.pdf', $stored);
        $this->assertTrue($disk->exists('secret/1/bank.pdf'));
        $this->assertSame(['secret/1/bank.pdf'], $disk->files('secret/1'));
        $this->assertSame(['secret/1'], $disk->directories('secret'));
        $this->assertGreaterThan(0, $disk->size('secret/1/bank.pdf'));

        $disk->copy('secret/1/bank.pdf', 'secret/1/copy.pdf');
        $disk->move('secret/1/copy.pdf', 'secret/2/moved.pdf');
        $this->assertTrue($disk->exists('secret/2/moved.pdf'));
        $this->assertFalse($disk->exists('secret/1/copy.pdf'));

        $disk->delete('secret/2/moved.pdf');
        $disk->deleteDirectory('secret/2');
        $this->assertFalse($disk->exists('secret/2'));
    }

    #[Test]
    public function copying_from_a_public_folder_into_a_private_one_lands_on_the_private_disk(): void
    {
        $this->uploads()->put('logos/host.png', 'png');

        $this->uploads()->copy('logos/host.png', 'secret/1/host.png');

        $this->assertSame('png', Storage::disk(config('files.private_disk'))->get('secret/1/host.png'));
        $this->assertTrue(Storage::disk('public')->exists('logos/host.png'));
    }

    #[Test]
    public function a_test_fake_of_the_public_disk_is_respected(): void
    {
        Storage::fake('public');

        $this->uploads()->put('logos/fake.png', 'png');

        Storage::disk('public')->assertExists('logos/fake.png');
    }
}
