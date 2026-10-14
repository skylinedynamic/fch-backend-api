<?php

namespace Tests\Feature\Website;

use App\Http\Controllers\Api\V1\FCH\Admin\CMS\BlogController;
use App\Models\CMS\Blog;
use App\Models\CMS\BlogCategory;
use App\Models\CMS\BlogTag;
use App\Models\Partner\Partner;
use App\Models\User\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/**
 * The host team's blog CMS (/admin/cms/blogs, blog-categories, blog-tags) -
 * the content the public website reads (see PublicWebsiteApiTest). Admin
 * routes: host team only.
 */
#[CoversClass(BlogController::class)]
class CmsBlogAdminApiTest extends TestCase
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
    }

    #[Test]
    public function the_cms_is_host_team_only(): void
    {
        $partner = $this->makeWhiteLabelPartner();

        foreach ([$this->makePartnerAdmin($partner), $this->makeBorrowerUser($partner)] as $user) {
            $this->actingAsUser($user);
            $this->getJson('/api/v1/admin/cms/blogs')->assertForbidden();
            $this->postJson('/api/v1/admin/cms/blogs', ['title' => 'x', 'content' => 'x'])->assertForbidden();
        }
    }

    #[Test]
    public function a_blog_post_is_written_published_and_deleted(): void
    {
        $this->actingAsUser($this->editor);
        $category = $this->postJson('/api/v1/admin/cms/blog-categories', ['name' => 'Funding'])->assertCreated()->json('data.id');

        $id = $this->postJson('/api/v1/admin/cms/blogs', [
            'title' => 'How to get funded',
            'content' => '<p>Body</p>',
            'category_id' => $category,
        ])->assertCreated()->json('data.id');

        $blog = Blog::find($id);
        $this->assertSame($this->host->id, (int) $blog->partner_id);
        $this->assertSame($this->editor->id, (int) $blog->author_id);
        $this->assertFalse((bool) $blog->is_published);

        $this->postJson("/api/v1/admin/cms/blogs/{$id}", ['is_published' => true])->assertOk();
        $this->assertTrue((bool) $blog->fresh()->is_published);
        $this->assertNotNull($blog->fresh()->published_at);

        $this->getJson("/api/v1/admin/cms/blogs/{$id}")->assertOk()->assertJsonPath('data.id', $id);
        $this->assertSame([$id], collect($this->getJson('/api/v1/admin/cms/blogs')->assertOk()->json('data'))->pluck('id')->all());

        $this->postJson('/api/v1/admin/cms/blogs-multiple-delete', ['ids' => [$id]])->assertOk();
        $this->assertNull(Blog::find($id));
        $this->getJson("/api/v1/admin/cms/blogs/{$id}")->assertNotFound();
    }

    #[Test]
    public function unpublishing_clears_the_publish_date(): void
    {
        $this->actingAsUser($this->editor);
        $id = $this->postJson('/api/v1/admin/cms/blogs', ['title' => 'Live', 'content' => 'x', 'is_published' => true])->json('data.id');
        $this->assertNotNull(Blog::find($id)->published_at);

        $this->postJson("/api/v1/admin/cms/blogs/{$id}", ['is_published' => false])->assertOk();
        $this->assertNull(Blog::find($id)->published_at);
    }

    #[Test]
    public function a_slug_is_unique(): void
    {
        $this->actingAsUser($this->editor);
        $this->postJson('/api/v1/admin/cms/blogs', ['title' => 'One', 'content' => 'x', 'slug' => 'same'])->assertCreated();

        $this->postJson('/api/v1/admin/cms/blogs', ['title' => 'Two', 'content' => 'x', 'slug' => 'same'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('slug');
    }

    /**
     * Defect: the category and tag requests read the record id from route
     * parameters named "category" / "tag", but the routes name it {id}, so
     * the unique-slug rule never ignored the record being edited - saving a
     * category or tag with its own slug unchanged failed as "slug taken".
     */
    #[Test]
    public function a_category_and_a_tag_save_with_their_own_slug_unchanged(): void
    {
        $this->actingAsUser($this->editor);
        $category = $this->postJson('/api/v1/admin/cms/blog-categories', ['name' => 'Funding', 'slug' => 'funding'])->assertCreated()->json('data.id');
        $tag = $this->postJson('/api/v1/admin/cms/blog-tags', ['name' => 'SBA', 'slug' => 'sba'])->assertCreated()->json('data.id');

        $this->postJson("/api/v1/admin/cms/blog-categories/{$category}", ['name' => 'Funding 101', 'slug' => 'funding'])->assertOk();
        $this->postJson("/api/v1/admin/cms/blog-tags/{$tag}", ['name' => 'SBA loans', 'slug' => 'sba'])->assertOk();

        $this->assertSame('Funding 101', BlogCategory::find($category)->name);
        $this->assertSame('SBA loans', BlogTag::find($tag)->name);
    }

    #[Test]
    public function another_categorys_slug_is_still_refused(): void
    {
        $this->actingAsUser($this->editor);
        $this->postJson('/api/v1/admin/cms/blog-categories', ['name' => 'A', 'slug' => 'a'])->assertCreated();
        $b = $this->postJson('/api/v1/admin/cms/blog-categories', ['name' => 'B', 'slug' => 'b'])->json('data.id');

        $this->postJson("/api/v1/admin/cms/blog-categories/{$b}", ['name' => 'B', 'slug' => 'a'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('slug');
    }

    #[Test]
    public function categories_and_tags_are_listed_and_deleted(): void
    {
        $this->actingAsUser($this->editor);
        $category = $this->postJson('/api/v1/admin/cms/blog-categories', ['name' => 'Funding'])->json('data.id');
        $tag = $this->postJson('/api/v1/admin/cms/blog-tags', ['name' => 'SBA'])->json('data.id');

        $this->getJson('/api/v1/admin/cms/blog-categories')->assertOk()->assertJsonPath('data.0.id', $category);
        $this->getJson('/api/v1/admin/cms/blog-tags')->assertOk()->assertJsonPath('data.0.id', $tag);

        $this->postJson('/api/v1/admin/cms/blog-categories-multiple-delete', ['ids' => [$category]])->assertOk();
        $this->postJson('/api/v1/admin/cms/blog-tags-multiple-delete', ['ids' => [$tag]])->assertOk();
        $this->assertNull(BlogCategory::find($category));
        $this->assertNull(BlogTag::find($tag));
        $this->postJson('/api/v1/admin/cms/blog-categories/999999', ['name' => 'Gone'])->assertNotFound();
    }
}
