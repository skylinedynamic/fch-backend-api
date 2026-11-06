<?php

namespace Tests\Unit\Files;

use App\Support\Files\StoredFileName;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Carbon\Carbon;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

/**
 * The name a file is kept under: the readable name first, then a short random
 * suffix - "tmfs-articles-of-organization_3f9a2c1e.pdf" - so two files with
 * the same name never overwrite each other. The original name is kept on the
 * record, not here.
 */
class StoredFileNameTest extends TestCase
{
    #[Test]
    public function the_readable_name_comes_first_then_a_short_random_suffix(): void
    {
        $name = StoredFileName::make('TMFS - Articles of Organization.pdf');

        $this->assertMatchesRegularExpression('/^tmfs-articles-of-organization_[0-9a-f]{8}\.pdf$/', $name);
    }

    #[Test]
    public function two_files_with_the_same_name_get_different_names(): void
    {
        $this->assertNotSame(StoredFileName::make('bank.pdf'), StoredFileName::make('bank.pdf'));
    }

    #[Test]
    public function a_path_or_odd_characters_never_reach_the_name(): void
    {
        $name = StoredFileName::make('../../2/w9 (final)!!.PDF');

        $this->assertStringNotContainsString('/', $name);
        $this->assertStringNotContainsString('..', $name);
        $this->assertMatchesRegularExpression('/^[a-z0-9-]+_[0-9a-f]{8}\.pdf$/', $name);
    }

    #[Test]
    public function a_long_name_is_cut_to_the_configured_length(): void
    {
        $name = StoredFileName::make(str_repeat('statement ', 30) . '.pdf');

        [$slug] = explode('_', $name);
        $this->assertLessThanOrEqual(config('files.name.max_slug_length'), strlen($slug));
    }

    #[Test]
    public function a_name_with_nothing_readable_still_gets_a_name(): void
    {
        $this->assertMatchesRegularExpression('/^file_[0-9a-f]{8}\.png$/', StoredFileName::make('!!! ###.png'));
    }

    #[Test]
    public function a_name_in_another_script_is_spelled_out_in_latin_letters(): void
    {
        $this->assertMatchesRegularExpression('/^[a-z0-9-]+_[0-9a-f]{8}\.png$/', StoredFileName::make('আমার ছবি.png'));
    }

    #[Test]
    public function the_extension_can_be_given_when_the_name_has_none(): void
    {
        $this->assertMatchesRegularExpression('/^scan_[0-9a-f]{8}\.jpg$/', StoredFileName::make('scan', 'JPG'));
    }

    #[Test]
    public function a_timed_name_keeps_the_name_first_then_the_time(): void
    {
        $this->travelTo(Carbon::createFromTimestamp(1793773507));

        $this->assertSame('Bank_Statement_Jan_1793773507.pdf', StoredFileName::timed('Bank Statement Jan.pdf'));
        $this->assertSame('Q1_report__final__1793773507.pdf', StoredFileName::timed('Q1 report (final).pdf'));
        $this->assertSame('file_1793773507.png', StoredFileName::timed('###.png'));
        $this->assertSame('w9_1793773507', StoredFileName::timed('w9'));
        $this->assertStringNotContainsString('/', StoredFileName::timed('../../2/w9.pdf'));
    }

    #[Test]
    public function a_timed_name_already_taken_gets_a_number_at_the_end(): void
    {
        $this->travelTo(Carbon::createFromTimestamp(1793773507));
        $disk = Storage::fake('public');
        $disk->put('partners/7/bank_1793773507.pdf', 'one');
        $this->assertSame('bank_1793773507_1.pdf', StoredFileName::timed('bank.pdf', $disk, 'partners/7'));

        $disk->put('partners/7/bank_1793773507_1.pdf', 'two');
        $this->assertSame('bank_1793773507_2.pdf', StoredFileName::timed('bank.pdf', $disk, 'partners/7'));
    }

    #[Test]
    public function storing_an_upload_keeps_every_file_of_the_same_name(): void
    {
        $this->travelTo(Carbon::createFromTimestamp(1793773507));
        $disk = Storage::fake('public');

        $paths = [];
        foreach (['one', 'two', 'three'] as $contents) {
            $paths[] = StoredFileName::store(UploadedFile::fake()->createWithContent('bank.pdf', $contents), $disk, 'partners/7');
        }

        $this->assertSame(['partners/7/bank_1793773507.pdf', 'partners/7/bank_1793773507_1.pdf', 'partners/7/bank_1793773507_2.pdf'], $paths);
        $this->assertSame(['one', 'two', 'three'], array_map(fn ($path) => $disk->get($path), $paths));
    }

    /**
     * Two uploads of one name at the same moment: the name is picked and the
     * file written under a lock for that folder and name, so the second one
     * waits for the first and then takes the next number.
     */
    #[Test]
    public function the_name_is_picked_and_written_under_a_lock_for_that_folder_and_name(): void
    {
        $this->travelTo(Carbon::createFromTimestamp(1793773507));
        $disk = Storage::fake('public');
        $held = Cache::lock(StoredFileName::lockKey('partners/7', 'bank.pdf'), 10);
        $held->get();

        try {
            StoredFileName::store(UploadedFile::fake()->createWithContent('bank.pdf', 'x'), $disk, 'partners/7', 0);
            $this->fail('The upload did not wait for the lock.');
        } catch (LockTimeoutException) {
            $this->assertSame([], $disk->allFiles('partners/7'));
        } finally {
            $held->release();
        }

        $this->assertSame('partners/7/bank_1793773507.pdf', StoredFileName::store(UploadedFile::fake()->createWithContent('bank.pdf', 'x'), $disk, 'partners/7'));
    }

    #[Test]
    public function an_anonymous_name_carries_nothing_of_the_original(): void
    {
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}\.pdf$/', StoredFileName::anonymous('pdf'));
    }
}
