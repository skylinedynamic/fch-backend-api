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
