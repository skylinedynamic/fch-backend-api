<?php

namespace Tests\Feature\Files;

use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Illuminate\Contracts\Filesystem\Filesystem;

/**
 * files:move-to-private {folder} - copies a folder from the public disk to its
 * private disk. A file is copied only once its copy matches byte for byte, and
 * the public original is deleted only with --delete-source, so the move can be
 * checked (and rolled back) before anything public is gone.
 */
class MoveFilesToPrivateCommandTest extends TestCase
{
    private const FILES = [
        'audit/files/aaa.pdf' => '%PDF signed one',
        'audit/files/bbb.pdf' => '%PDF signed two',
        'audit/seals/2026-11-03.json' => '{"applications":[]}',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        foreach (self::FILES as $path => $contents) {
            Storage::disk('public')->put($path, $contents);
        }
    }

    private function private(): Filesystem
    {
        return Storage::disk(config('files.private_folders.audit'));
    }

    #[Test]
    public function the_audit_folder_is_copied_to_its_private_disk_and_the_public_copy_is_kept(): void
    {
        $this->artisan('files:move-to-private', ['folder' => 'audit'])->assertSuccessful();

        foreach (self::FILES as $path => $contents) {
            $this->assertSame($contents, $this->private()->get($path));
            $this->assertTrue(Storage::disk('public')->exists($path), "{$path} was deleted without --delete-source");
        }
    }

    #[Test]
    public function with_delete_source_the_public_copy_goes_once_the_private_one_matches(): void
    {
        $this->artisan('files:move-to-private', ['folder' => 'audit', '--delete-source' => true])->assertSuccessful();

        foreach (self::FILES as $path => $contents) {
            $this->assertSame($contents, $this->private()->get($path));
        }
        $this->assertSame([], Storage::disk('public')->allFiles('audit'));
    }

    #[Test]
    public function a_dry_run_changes_nothing(): void
    {
        $this->artisan('files:move-to-private', ['folder' => 'audit', '--dry-run' => true, '--delete-source' => true])
            ->assertSuccessful();

        $this->assertSame([], $this->private()->allFiles('audit'));
        $this->assertCount(count(self::FILES), Storage::disk('public')->allFiles('audit'));
    }

    #[Test]
    public function running_it_again_changes_nothing(): void
    {
        $this->artisan('files:move-to-private', ['folder' => 'audit'])->assertSuccessful();
        $this->artisan('files:move-to-private', ['folder' => 'audit', '--delete-source' => true])->assertSuccessful();
        $this->artisan('files:move-to-private', ['folder' => 'audit', '--delete-source' => true])->assertSuccessful();

        $this->assertCount(count(self::FILES), $this->private()->allFiles('audit'));
    }

    #[Test]
    public function a_different_file_already_at_the_private_path_is_never_overwritten_or_lost(): void
    {
        $this->private()->put('audit/files/aaa.pdf', '%PDF something else');

        $this->artisan('files:move-to-private', ['folder' => 'audit', '--delete-source' => true])->assertFailed();

        $this->assertSame('%PDF something else', $this->private()->get('audit/files/aaa.pdf'));
        $this->assertSame('%PDF signed one', Storage::disk('public')->get('audit/files/aaa.pdf'));
    }

    #[Test]
    public function only_a_configured_private_folder_can_be_moved(): void
    {
        Storage::disk('public')->put('logos/host.png', 'png');

        $this->artisan('files:move-to-private', ['folder' => 'logos'])->assertFailed();

        $this->assertTrue(Storage::disk('public')->exists('logos/host.png'));
    }
}
