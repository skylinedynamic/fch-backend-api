<?php

namespace Tests\Feature\Website;

use App\Http\Requests\Api\V1\FCH\Admin\CMS\SeoRequest;
use App\Models\CMS\SeoSetting;
use App\Models\Partner\Partner;
use App\Models\User\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/**
 * R3-BUG-015: an SEO title is at most 120 characters,
 * on both Add and Edit. A longer title saved before the limit is kept while it
 * is left unchanged.
 */
#[CoversClass(SeoRequest::class)]
class SeoMetaTitleLengthTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithPortalUsers;

    private Partner $host;
    private User $editor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->host = $this->makeHostPartner();
        $this->editor = $this->makeStaffUser($this->host);
        $this->actingAsUser($this->editor);
    }

    private function seo(string $title, string $path = '/about'): SeoSetting
    {
        return SeoSetting::create(['partner_id' => $this->host->id, 'page_name' => 'About Us', 'path' => $path, 'meta_title' => $title]);
    }

    #[Test]
    public function the_limit_is_120_characters(): void
    {
        $this->assertSame(120, SeoSetting::META_TITLE_MAX_LENGTH);
    }

    #[Test]
    public function a_new_entry_takes_a_120_character_title(): void
    {
        $this->postJson('/api/v1/admin/cms/seo-settings/store', ['page_name' => 'Home', 'path' => '/', 'meta_title' => str_repeat('a', 120)])
            ->assertCreated();
    }

    #[Test]
    public function a_new_entry_refuses_a_longer_title(): void
    {
        $this->postJson('/api/v1/admin/cms/seo-settings/store', ['page_name' => 'Home', 'path' => '/', 'meta_title' => str_repeat('a', 121)])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['meta_title' => 'The SEO title may not be longer than 120 characters.']);

        $this->assertSame(0, SeoSetting::count());
    }

    #[Test]
    public function an_edit_refuses_a_121_character_title(): void
    {
        $original = 'About Four Corner Funding | Fintech Funding Platform';
        $seo = $this->seo($original);

        $this->postJson("/api/v1/admin/cms/seo-settings/{$seo->id}", ['page_name' => 'About Us', 'path' => '/about', 'meta_title' => str_pad($original . ' QA-TEST R3 long title check ', 121, 'x')])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('meta_title');

        $this->assertSame($original, $seo->fresh()->meta_title);
    }

    #[Test]
    public function an_edit_takes_a_title_within_the_limit(): void
    {
        $seo = $this->seo('Old title');

        $this->postJson("/api/v1/admin/cms/seo-settings/{$seo->id}", ['page_name' => 'About Us', 'path' => '/about', 'meta_title' => str_repeat('b', 120)])
            ->assertOk();

        $this->assertSame(str_repeat('b', 120), $seo->fresh()->meta_title);
    }

    #[Test]
    public function an_older_long_title_left_unchanged_still_saves(): void
    {
        $long = str_repeat('c', 150);
        $seo = $this->seo($long);

        $this->postJson("/api/v1/admin/cms/seo-settings/{$seo->id}", ['page_name' => 'About Page', 'path' => '/about', 'meta_title' => $long])
            ->assertOk();

        $this->assertSame('About Page', $seo->fresh()->page_name);
    }

    #[Test]
    public function an_older_long_title_cannot_be_swapped_for_another_long_one(): void
    {
        $seo = $this->seo(str_repeat('c', 150));

        $this->postJson("/api/v1/admin/cms/seo-settings/{$seo->id}", ['page_name' => 'About Us', 'path' => '/about', 'meta_title' => str_repeat('d', 150)])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('meta_title');
    }

    #[Test]
    public function another_partners_long_title_does_not_open_the_limit(): void
    {
        $long = str_repeat('e', 150);
        $other = $this->makeWhiteLabelPartner();
        SeoSetting::create(['partner_id' => $other->id, 'page_name' => 'X', 'path' => '/x', 'meta_title' => $long]);

        $this->postJson('/api/v1/admin/cms/seo-settings/store', ['page_name' => 'Home', 'path' => '/', 'meta_title' => $long])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('meta_title');
    }
}
