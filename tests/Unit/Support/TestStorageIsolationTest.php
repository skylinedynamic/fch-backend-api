<?php

namespace Tests\Unit\Support;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The base TestCase keeps every test out of the developer's real storage/.
 *
 * Defect: nothing stopped a test from writing to - or deleting from - the real
 * storage/app/public. Three tests did, with ids 1-2 that matched real local
 * folders, and a borrower's funding-application documents were lost. Each test
 * then had to remember to isolate itself, and one still did not.
 */
class TestStorageIsolationTest extends TestCase
{
    private function realStorage(): string
    {
        return $this->normalise(base_path('storage'));
    }

    private function normalise(string $path): string
    {
        return rtrim(str_replace('\\', '/', $path), '/');
    }

    #[Test]
    public function storage_path_is_a_throwaway_folder(): void
    {
        $this->assertStringStartsNotWith($this->realStorage(), $this->normalise(storage_path()));
        $this->assertDirectoryExists(storage_path('app/public'));
    }

    #[Test]
    public function the_disks_write_into_the_throwaway_folder(): void
    {
        foreach (['local', 'public'] as $disk) {
            $root = $this->normalise((string) config("filesystems.disks.{$disk}.root"));
            $this->assertStringStartsNotWith($this->realStorage(), $root, "disk {$disk}");
        }

        Storage::disk('public')->put('borrowers/1/isolation-probe.txt', 'x');

        $this->assertFileDoesNotExist(base_path('storage/app/public/borrowers/1/isolation-probe.txt'));
        $this->assertFileExists(storage_path('app/public/borrowers/1/isolation-probe.txt'));
    }

    #[Test]
    public function logs_are_written_into_the_throwaway_folder(): void
    {
        foreach ((array) config('logging.channels') as $name => $channel) {
            if (isset($channel['path'])) {
                $this->assertStringStartsNotWith($this->realStorage(), $this->normalise($channel['path']), "channel {$name}");
            }
        }

        Log::info('isolation probe');
        $this->assertTrue(true);
    }

    #[Test]
    public function passport_keys_are_available_in_the_throwaway_folder(): void
    {
        foreach (glob(base_path('storage/oauth-*.key')) as $key) {
            $this->assertFileExists(storage_path(basename($key)));
        }
        $this->assertTrue(true);
    }

    /**
     * Defect: the copies took the umask default (644), and league/oauth2-server
     * refuses a key that others can read - every test that signed a token failed
     * on the Linux CI runner. Windows skips that check, so it passed locally.
     */
    #[Test]
    public function the_copied_passport_keys_are_readable_only_by_their_owner(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('Windows has no POSIX permissions; oauth2-server skips the check there.');
        }

        $keys = glob(storage_path('oauth-*.key')) ?: [];
        $this->assertNotEmpty($keys, 'no Passport keys were copied into the throwaway folder');

        foreach ($keys as $key) {
            $this->assertSame('600', decoct(fileperms($key) & 0777), basename($key));
        }
    }

    /**
     * Five tests that pointed storage at a folder of their own copied the keys
     * themselves, missed the 644 fix and failed on CI only. Windows cannot show
     * that, so this checks the source instead: keys are copied in one place.
     */
    #[Test]
    public function no_test_copies_the_passport_keys_except_through_the_helper(): void
    {
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path('tests'), \FilesystemIterator::SKIP_DOTS));
        $offenders = [];

        foreach ($files as $file) {
            $path = str_replace('\\', '/', $file->getPathname());
            if ($file->getExtension() !== 'php' || str_ends_with($path, 'tests/TestCase.php')) {
                continue;
            }
            if (preg_match("/glob\([^;]*oauth-\*\.key[^;]*\)\s*(?:\?:\s*\[\])?\s*as[^)]*\)\s*\{[^}]*copy\(/s", file_get_contents($path))) {
                $offenders[] = substr($path, strlen(str_replace('\\', '/', base_path())) + 1);
            }
        }

        $this->assertSame([], $offenders, 'copy the Passport keys with $this->copyPassportKeys() so they get 600');
    }

    #[Test]
    public function each_test_gets_its_own_folder_that_is_removed_afterwards(): void
    {
        $first = storage_path();
        $this->tearDown();
        $this->assertDirectoryDoesNotExist($first);

        $this->setUp();
        $this->assertNotSame($first, storage_path());
    }
}
