<?php

namespace Tests\Feature\Website;

use App\Http\Controllers\Api\V1\FCH\Admin\CMS\BlogController;
use App\Models\CMS\Blog;
use App\Models\CMS\BlogCategory;
use App\Models\CMS\BlogTag;
use App\Models\Partner\Partner;
use App\Models\User\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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

    /** @return array<int, string> titles on one page of the admin list */
    private function blogTitles(array $query): array
    {
        return collect($this->getJson('/api/v1/admin/cms/blogs?' . http_build_query($query))->assertOk()->json('data'))
            ->pluck('title')->all();
    }

    /**
     * Defect: BUG-184 - CATEGORY sorted by category id (creation order), not by
     * the category name the column shows.
     */
    #[Test]
    public function the_category_column_sorts_by_category_name(): void
    {
        $this->actingAsUser($this->editor);
        // Created Z first, so id order and name order differ.
        $zebra = BlogCategory::create(['name' => 'Zebra Tips', 'partner_id' => $this->host->id])->id;
        $apple = BlogCategory::create(['name' => 'Apple Advice', 'partner_id' => $this->host->id])->id;
        $mango = BlogCategory::create(['name' => 'Mango Money', 'partner_id' => $this->host->id])->id;
        foreach (['Z post' => $zebra, 'A post' => $apple, 'M post' => $mango] as $title => $category) {
            Blog::create(['title' => $title, 'slug' => str($title)->slug(), 'content' => 'x', 'category_id' => $category, 'partner_id' => $this->host->id]);
        }

        $this->assertSame(['A post', 'M post', 'Z post'], $this->blogTitles(['sort_field' => 'category.name', 'sort_order' => 'asc']));
        $this->assertSame(['Z post', 'M post', 'A post'], $this->blogTitles(['sort_field' => 'category.name', 'sort_order' => 'desc']));
    }

    /**
     * Defect: BUG-185 - sorted by Status (or Category), rows with the same value
     * had no tie-breaker, so MySQL returned them in a different order for each
     * page: some posts appeared on two pages and others on none. SQLite returns
     * ties in insert order, so the repeat itself cannot be reproduced here; the
     * test checks every post is on exactly one page and that the ORDER BY ends
     * on the id.
     */
    #[Test]
    public function every_post_is_on_exactly_one_page_when_sorted_by_a_repeated_value(): void
    {
        $this->actingAsUser($this->editor);
        $category = BlogCategory::create(['name' => 'Shared', 'partner_id' => $this->host->id])->id;
        for ($i = 1; $i <= 12; $i++) {
            Blog::create(['title' => "Post {$i}", 'slug' => "post-{$i}", 'content' => 'x', 'category_id' => $category, 'is_published' => $i % 2 === 0, 'partner_id' => $this->host->id]);
        }

        foreach (['status', 'category.name'] as $field) {
            DB::enableQueryLog();
            $seen = [];
            foreach ([1, 2, 3] as $page) {
                $seen = array_merge($seen, $this->blogTitles(['sort_field' => $field, 'sort_order' => 'asc', 'per_page' => 5, 'page' => $page]));
            }
            $sql = collect(DB::getQueryLog())->pluck('query')->first(fn ($q) => str_contains($q, 'order by') && str_contains($q, 'limit'));
            DB::flushQueryLog();

            $this->assertCount(12, $seen, "{$field}: not 12 rows over 3 pages");
            $this->assertCount(12, array_unique($seen), "{$field}: a post was repeated");
            $this->assertMatchesRegularExpression('/order by .*"?id"? (asc|desc) limit/i', $sql, "{$field}: no id tie-breaker");
        }
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

    // -- BUG-045: a repeated title gets its own web address -------------------

    #[Test]
    public function a_repeated_title_gets_a_numbered_slug_instead_of_failing(): void
    {
        $this->actingAsUser($this->editor);
        $post = fn () => $this->postJson('/api/v1/admin/cms/blogs', ['title' => 'QA Draft Post', 'content' => 'x'])->assertCreated()->json('data.id');

        $first = $post();
        $second = $post();
        $third = $post();

        $this->assertSame('qa-draft-post', Blog::find($first)->slug);
        $this->assertSame('qa-draft-post-2', Blog::find($second)->slug);
        $this->assertSame('qa-draft-post-3', Blog::find($third)->slug);
    }

    #[Test]
    public function a_deleted_posts_slug_is_not_reused(): void
    {
        // The (slug, partner) unique index covers soft-deleted posts too.
        $this->actingAsUser($this->editor);
        $first = $this->postJson('/api/v1/admin/cms/blogs', ['title' => 'Gone', 'content' => 'x'])->json('data.id');
        $this->postJson('/api/v1/admin/cms/blogs-multiple-delete', ['ids' => [$first]])->assertOk();

        $again = $this->postJson('/api/v1/admin/cms/blogs', ['title' => 'Gone', 'content' => 'x'])->assertCreated()->json('data.id');

        $this->assertSame('gone-2', Blog::find($again)->slug);
    }

    #[Test]
    public function editing_a_post_keeps_its_web_address(): void
    {
        $this->actingAsUser($this->editor);
        $id = $this->postJson('/api/v1/admin/cms/blogs', ['title' => 'Original title', 'content' => 'x'])->json('data.id');

        $this->postJson("/api/v1/admin/cms/blogs/{$id}", ['title' => 'A better title', 'content' => 'y'])->assertOk();

        $this->assertSame('original-title', Blog::find($id)->slug);
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

    /**
     * Defect (BUG-059): "QA-TEST Category" could be added three times, and a
     * tag twice — names were not checked, only slugs (which were auto-numbered).
     */
    #[Test]
    public function a_category_or_tag_name_cannot_be_repeated(): void
    {
        $this->actingAsUser($this->editor);

        foreach (['blog-categories', 'blog-tags'] as $resource) {
            $first = $this->postJson("/api/v1/admin/cms/{$resource}", ['name' => 'QA-TEST Name'])->assertCreated()->json('data.id');
            $second = $this->postJson("/api/v1/admin/cms/{$resource}", ['name' => 'Other'])->assertCreated()->json('data.id');

            $this->postJson("/api/v1/admin/cms/{$resource}", ['name' => '  QA-TEST Name '])
                ->assertUnprocessable()
                ->assertJsonValidationErrors('name');
            $this->postJson("/api/v1/admin/cms/{$resource}/{$second}", ['name' => 'QA-TEST Name'])
                ->assertUnprocessable()
                ->assertJsonValidationErrors('name');

            // Saving a record under its own name is not a duplicate.
            $this->postJson("/api/v1/admin/cms/{$resource}/{$first}", ['name' => 'QA-TEST Name'])->assertOk();
        }
    }

    /** BUG-059: the admin's Existing Categories / Tags list the newest first. */
    #[Test]
    public function categories_and_tags_are_listed_newest_first(): void
    {
        $this->actingAsUser($this->editor);

        foreach (['blog-categories', 'blog-tags'] as $resource) {
            $this->travelTo(now()->subDays(2));
            $this->postJson("/api/v1/admin/cms/{$resource}", ['name' => 'Oldest'])->assertCreated();
            $this->travelBack();
            $this->travelTo(now()->subDay());
            $this->postJson("/api/v1/admin/cms/{$resource}", ['name' => 'Middle'])->assertCreated();
            $this->travelBack();
            $this->postJson("/api/v1/admin/cms/{$resource}", ['name' => 'Newest'])->assertCreated();

            $this->assertSame(['Newest', 'Middle', 'Oldest'], collect($this->getJson("/api/v1/admin/cms/{$resource}")->assertOk()->json('data'))->pluck('name')->all());
        }
    }

    /**
     * BUG-059: a category or tag on a blog post was deleted anyway. It is kept:
     * the API answers 422 naming it (errors.blocked), deletes nothing in that
     * call, and an unused one is still deleted.
     */
    #[Test]
    public function a_category_or_tag_on_a_blog_post_is_not_deleted(): void
    {
        $this->actingAsUser($this->editor);
        $used = $this->postJson('/api/v1/admin/cms/blog-categories', ['name' => 'Used Category'])->json('data.id');
        $free = $this->postJson('/api/v1/admin/cms/blog-categories', ['name' => 'Free Category'])->json('data.id');
        $usedTag = $this->postJson('/api/v1/admin/cms/blog-tags', ['name' => 'Used Tag'])->json('data.id');
        $freeTag = $this->postJson('/api/v1/admin/cms/blog-tags', ['name' => 'Free Tag'])->json('data.id');
        $this->postJson('/api/v1/admin/cms/blogs', ['title' => 'Funding 101', 'content' => '<p>Body</p>', 'category_id' => $used, 'blog_tag_id' => [$usedTag]])->assertCreated();

        $this->postJson('/api/v1/admin/cms/blog-categories-multiple-delete', ['ids' => [$used, $free]])
            ->assertStatus(422)
            ->assertJsonPath('message', __('blog.category_in_use'))
            ->assertJsonPath('errors.blocked', [['id' => $used, 'name' => 'Used Category']]);
        $this->assertNotNull(BlogCategory::find($used));
        $this->assertNotNull(BlogCategory::find($free), 'all or none: nothing is deleted when one is refused');

        $this->postJson('/api/v1/admin/cms/blog-tags-multiple-delete', ['ids' => [$usedTag]])
            ->assertStatus(422)
            ->assertJsonPath('message', __('blog.tag_in_use'))
            ->assertJsonPath('errors.blocked', [['id' => $usedTag, 'name' => 'Used Tag']]);
        $this->assertNotNull(BlogTag::find($usedTag));

        $this->postJson('/api/v1/admin/cms/blog-categories-multiple-delete', ['ids' => [$free]])->assertOk();
        $this->postJson('/api/v1/admin/cms/blog-tags-multiple-delete', ['ids' => [$freeTag]])->assertOk();
        $this->assertNull(BlogCategory::find($free));
        $this->assertNull(BlogTag::find($freeTag));
    }

    /** The tag list counts the posts carrying each tag, so the editor can tell a used tag apart. */
    #[Test]
    public function the_tag_list_counts_the_posts_carrying_each_tag(): void
    {
        $this->actingAsUser($this->editor);
        $a = $this->postJson('/api/v1/admin/cms/blog-tags', ['name' => 'A'])->json('data.id');
        $b = $this->postJson('/api/v1/admin/cms/blog-tags', ['name' => 'B'])->json('data.id');
        $this->postJson('/api/v1/admin/cms/blog-tags', ['name' => 'C'])->assertCreated();
        $this->postJson('/api/v1/admin/cms/blogs', ['title' => 'One', 'content' => '<p>x</p>', 'blog_tag_id' => [$a, $b]])->assertCreated();
        $this->postJson('/api/v1/admin/cms/blogs', ['title' => 'Two', 'content' => '<p>x</p>', 'blog_tag_id' => [$a]])->assertCreated();

        $counts = collect($this->getJson('/api/v1/admin/cms/blog-tags')->assertOk()->json('data'))->pluck('blogs_count', 'name')->sortKeys()->all();
        $this->assertSame(['A' => 2, 'B' => 1, 'C' => 0], $counts);
    }

    #[Test]
    public function a_deleted_category_name_can_be_used_again(): void
    {
        $this->actingAsUser($this->editor);
        $id = $this->postJson('/api/v1/admin/cms/blog-categories', ['name' => 'Funding'])->json('data.id');
        $this->postJson('/api/v1/admin/cms/blog-categories-multiple-delete', ['ids' => [$id]])->assertOk();

        $this->postJson('/api/v1/admin/cms/blog-categories', ['name' => 'Funding'])->assertCreated();
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
