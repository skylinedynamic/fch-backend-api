<?php

namespace Tests\Unit\Files;

use App\Support\Files\StoragePath;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A file's path on the uploads disk, from whatever was saved or handed out for
 * it: a /storage URL (public), a signed /private-files URL (private, with its
 * query), or the path itself.
 */
class StoragePathTest extends TestCase
{
    public static function urls(): array
    {
        return [
            'public url' => ['http://127.0.0.1:8000/storage/borrowers/167/credit_report_1.pdf', 'borrowers/167/credit_report_1.pdf'],
            'signed private url' => ['https://api.x.com/private-files/borrowers/7/bank_3f9a2c1e.pdf?expires=1&signature=abc', 'borrowers/7/bank_3f9a2c1e.pdf'],
            'encoded url' => ['http://h/storage/partners/1/Craig%20Rice%20.jpg', 'partners/1/Craig Rice .jpg'],
            'a path' => ['borrowers/7/a.pdf', 'borrowers/7/a.pdf'],
            'a path with a leading slash' => ['/borrowers/7/a.pdf', 'borrowers/7/a.pdf'],
        ];
    }

    #[Test]
    #[DataProvider('urls')]
    public function it_reads_the_path_from_a_url_or_a_path(string $given, string $path): void
    {
        $this->assertSame($path, StoragePath::fromUrl($given));
    }

    #[Test]
    public function nothing_gives_no_path(): void
    {
        $this->assertNull(StoragePath::fromUrl(null));
        $this->assertNull(StoragePath::fromUrl(''));
    }

    #[Test]
    public function a_url_somewhere_else_gives_no_path(): void
    {
        $this->assertNull(StoragePath::fromUrl('https://evil.example.com/latest/meta-data'));
    }

    #[Test]
    public function a_path_that_climbs_out_gives_no_path(): void
    {
        $this->assertNull(StoragePath::fromUrl('http://h/storage/borrowers/../../.env'));
        $this->assertNull(StoragePath::fromUrl('../.env'));
    }
}
