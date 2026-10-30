<?php

namespace Tests\Feature\Agreement;

use App\Console\Commands\MoveAgreementImagesToDisk;
use App\Models\Common\AgreementSign;
use App\Models\Common\AgreementTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/**
 * One-off: rows saved before agreement images moved to disk still hold them
 * as base64. The command moves them out, leaving each row reading back
 * exactly as before.
 */
#[CoversClass(MoveAgreementImagesToDisk::class)]
class MoveAgreementImagesToDiskTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithPortalUsers;

    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

    private string $html;

    private int $templateId;

    private int $signId;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        config(['agreement.image_disk' => 'local', 'agreement.image_directory' => 'agreement-images']);

        $partner = $this->makeWhiteLabelPartner();
        $user = $this->makeBorrowerUser($partner);
        $this->html = '<p>Terms</p><img src="data:image/png;base64,' . self::PNG . '"/>';

        // Written straight to the table, as the old code stored them.
        $this->templateId = DB::table('agreement_templates')->insertGetId([
            'partner_id' => $partner->id, 'slug' => 'borrower', 'is_active' => true,
            'template_html' => $this->html, 'template_json' => '{"blocks":[{"logoSrc":"data:image/png;base64,' . self::PNG . '"}]}',
            'created_at' => now()->subDay(), 'updated_at' => now()->subDay(),
        ]);
        $this->signId = DB::table('agreement_signs')->insertGetId([
            'user_id' => $user->id, 'entity_type' => $user->entity_type, 'entity_id' => $user->entity_id,
            'agreement_template_id' => $this->templateId, 'ip_address' => '127.0.0.1', 'content_html' => $this->html,
            'created_at' => now()->subDay(), 'updated_at' => now()->subDay(),
        ]);
    }

    #[Test]
    public function it_moves_old_rows_images_to_disk_and_they_read_back_the_same(): void
    {
        $updatedAt = DB::table('agreement_signs')->where('id', $this->signId)->value('updated_at');

        $this->artisan('agreements:move-images-to-disk')
            ->expectsOutputToContain('2 rows updated')
            ->assertSuccessful();

        foreach ([['agreement_templates', 'template_html'], ['agreement_templates', 'template_json'], ['agreement_signs', 'content_html']] as [$table, $column]) {
            $this->assertStringNotContainsString('base64', DB::table($table)->value($column), "{$table}.{$column}");
        }
        $this->assertCount(1, Storage::disk('local')->allFiles('agreement-images'));
        $this->assertSame($this->html, AgreementTemplate::find($this->templateId)->template_html);
        $this->assertSame($this->html, AgreementSign::find($this->signId)->content_html);
        // A signed agreement's record is not "changed" by moving its image.
        $this->assertSame($updatedAt, DB::table('agreement_signs')->where('id', $this->signId)->value('updated_at'));
    }

    #[Test]
    public function running_it_again_changes_nothing(): void
    {
        $this->artisan('agreements:move-images-to-disk')->assertSuccessful();
        $before = DB::table('agreement_signs')->where('id', $this->signId)->value('content_html');

        $this->artisan('agreements:move-images-to-disk')
            ->expectsOutputToContain('0 rows updated')
            ->assertSuccessful();

        $this->assertSame($before, DB::table('agreement_signs')->where('id', $this->signId)->value('content_html'));
    }

    #[Test]
    public function a_row_with_some_images_already_moved_gets_the_rest_moved(): void
    {
        $this->artisan('agreements:move-images-to-disk')->assertSuccessful();
        $partlyMoved = DB::table('agreement_signs')->where('id', $this->signId)->value('content_html')
            . '<img src="data:image/heic;base64,' . self::PNG . '"/>';
        DB::table('agreement_signs')->where('id', $this->signId)->update(['content_html' => $partlyMoved]);

        $this->artisan('agreements:move-images-to-disk')
            ->expectsOutputToContain('1 rows updated')
            ->assertSuccessful();

        $this->assertStringNotContainsString('base64', DB::table('agreement_signs')->where('id', $this->signId)->value('content_html'));
        $this->assertSame(
            $this->html . '<img src="data:image/heic;base64,' . self::PNG . '"/>',
            AgreementSign::find($this->signId)->content_html
        );
    }

    #[Test]
    public function a_dry_run_only_reports(): void
    {
        $this->artisan('agreements:move-images-to-disk', ['--dry-run' => true])
            ->expectsOutputToContain('2 rows would be updated')
            ->assertSuccessful();

        $this->assertSame($this->html, DB::table('agreement_signs')->where('id', $this->signId)->value('content_html'));
        $this->assertSame([], Storage::disk('local')->allFiles());
    }
}
