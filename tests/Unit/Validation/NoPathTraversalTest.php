<?php

namespace Tests\Unit\Validation;

use App\Rules\NoPathTraversal;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A relative path that never steps up a folder: any ".." segment, with
 * either slash, is refused; dots inside a name are not a segment.
 */
class NoPathTraversalTest extends TestCase
{
    private function fails(mixed $value): bool
    {
        return Validator::make(['path' => $value], ['path' => ['nullable', new NoPathTraversal()]])->fails();
    }

    public static function climbingPaths(): array
    {
        return [
            'leading' => ['../2/w9.pdf'],
            'deep' => ['../../../../.env'],
            'in the middle' => ['documents/../../2/w9.pdf'],
            'trailing' => ['documents/..'],
            'alone' => ['..'],
            'backslashes' => ['..\\2\\w9.pdf'],
            'mixed slashes' => ['documents\\../2/w9.pdf'],
        ];
    }

    #[Test]
    #[DataProvider('climbingPaths')]
    public function a_path_with_a_parent_segment_fails(string $path): void
    {
        $this->assertTrue($this->fails($path));
    }

    public static function plainPaths(): array
    {
        return [
            'name' => ['w9.pdf'],
            'sub folder' => ['documents/w9.pdf'],
            'leading slash' => ['/documents/w9.pdf'],
            'dots in a name' => ['w9..final.pdf'],
            'dot folder' => ['./w9.pdf'],
            'dotted folder name' => ['..hidden/w9.pdf'],
        ];
    }

    #[Test]
    #[DataProvider('plainPaths')]
    public function a_path_that_stays_put_passes(string $path): void
    {
        $this->assertFalse($this->fails($path));
    }

    #[Test]
    public function empty_values_and_uploaded_files_are_left_to_other_rules(): void
    {
        $this->assertFalse($this->fails(null));
        $this->assertFalse($this->fails(''));
        $this->assertFalse($this->fails(UploadedFile::fake()->create('w9.pdf')));
    }

    #[Test]
    public function the_failure_says_why(): void
    {
        $errors = Validator::make(['path' => '../x'], ['path' => [new NoPathTraversal()]])->errors();

        $this->assertSame(__('common.path_traversal'), $errors->first('path'));
    }
}
