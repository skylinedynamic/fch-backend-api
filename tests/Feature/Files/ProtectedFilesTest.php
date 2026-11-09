<?php

namespace Tests\Feature\Files;

use App\Services\Files\ProtectedFiles;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * BUG-016: a private file is never reachable by its storage path. It is handed
 * out only by a short-lived signed URL, issued after the caller's own access
 * check, for that one file.
 */
class ProtectedFilesTest extends TestCase
{
    private const PATH = 'audit/files/abc.pdf';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::disk(config('files.private_disk'))->put(self::PATH, '%PDF private');
    }

    #[Test]
    public function the_private_disk_is_not_the_public_one(): void
    {
        $this->assertNotSame('public', config('files.private_disk'));
        $this->assertNotSame(
            Storage::disk('public')->path(''),
            Storage::disk(config('files.private_disk'))->path('')
        );
    }

    #[Test]
    public function a_signed_url_hands_out_the_file(): void
    {
        $url = app(ProtectedFiles::class)->temporaryUrl(config('files.private_disk'), self::PATH);

        $this->assertStringContainsString('/private-files/', $url);
        $this->get($url)->assertOk()->assertStreamedContent('%PDF private');
    }

    #[Test]
    public function the_file_is_not_served_without_a_signature(): void
    {
        $this->get('/private-files/' . self::PATH)->assertForbidden();
    }

    #[Test]
    public function a_signed_url_for_one_file_does_not_open_another(): void
    {
        Storage::disk(config('files.private_disk'))->put('audit/files/other.pdf', '%PDF other');
        $url = app(ProtectedFiles::class)->temporaryUrl(config('files.private_disk'), self::PATH);

        $this->get(str_replace('abc.pdf', 'other.pdf', $url))->assertForbidden();
    }

    #[Test]
    public function a_signed_url_stops_working_once_it_expires(): void
    {
        $url = app(ProtectedFiles::class)->temporaryUrl(config('files.private_disk'), self::PATH);

        $this->travel(config('files.signed_url_minutes') + 1)->minutes();

        $this->get($url)->assertForbidden();
    }

    #[Test]
    public function the_public_storage_url_does_not_reach_a_private_file(): void
    {
        $this->assertFalse(Storage::disk('public')->exists(self::PATH));
        $this->get('/storage/' . self::PATH)->assertNotFound();
    }
}
