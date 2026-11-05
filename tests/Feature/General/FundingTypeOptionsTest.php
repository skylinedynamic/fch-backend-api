<?php

namespace Tests\Feature\General;

use App\Http\Controllers\Api\V1\Global\General\LenderProductCategoryController;
use App\Http\Controllers\Web\EmbedFormPageController;
use App\Models\EmbedForm\EmbedFormConfig;
use App\Models\Lender\LenderProductCategory;
use App\Services\Global\General\LenderProductCategoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/**
 * Preferred and Additional Funding Types offer the lender product categories
 * (Lenders > Product Categories) - the same list a lender is set up with - so
 * an application's funding types are the categories its lenders are matched
 * on, exactly. One list for the admin and borrower wizards and the embed form.
 */
#[CoversClass(LenderProductCategoryController::class)]
#[CoversClass(LenderProductCategoryService::class)]
#[CoversClass(LenderProductCategory::class)]
#[CoversClass(EmbedFormPageController::class)]
class FundingTypeOptionsTest extends TestCase
{
    use InteractsWithPortalUsers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->category('Term Loan', 2);
        $this->category('Business Line of Credit', 1);
        $this->category('Old Product', 3, 'Inactive');
        $deleted = $this->category('Deleted Product', 4);
        DB::table('lender_product_categories')->where('id', $deleted)->update(['deleted_at' => now()]);
    }

    /** The one funding type API every portal uses: GET /api/v1/common/funding-types. */
    #[Test]
    public function every_portal_gets_the_active_categories_in_their_order(): void
    {
        $host = $this->makeHostPartner();
        $whiteLabel = $this->makeWhiteLabelPartner();

        foreach ([$this->makePartnerAdmin($host), $this->makePartnerAdmin($whiteLabel), $this->makeBorrowerUser($whiteLabel)] as $user) {
            $this->actingAsUser($user);

            $this->getJson('/api/v1/common/funding-types')
                ->assertOk()
                ->assertExactJson([
                    'status' => true,
                    'message' => __('lender.category_fetched'),
                    'data' => ['Business Line of Credit', 'Term Loan'],
                ]);
        }
    }

    /** Public reference data, like common/locations: the embed form and sign-up need no token. */
    #[Test]
    public function a_visitor_who_is_not_signed_in_gets_them_too(): void
    {
        $this->getJson('/api/v1/common/funding-types')
            ->assertOk()
            ->assertJsonPath('data', ['Business Line of Credit', 'Term Loan']);
    }

    #[Test]
    public function a_failure_loading_them_is_a_server_error_and_logged(): void
    {
        Log::spy();
        $this->mock(LenderProductCategoryService::class, function ($service) {
            $service->shouldReceive('fundingTypeNames')->andThrow(new RuntimeException('database gone'));
        });

        $this->getJson('/api/v1/common/funding-types')
            ->assertStatus(500)
            ->assertJsonPath('message', __('lender.category_fetch_failed'));

        Log::shouldHaveReceived('error')->withArgs(fn ($message) => str_contains($message, 'database gone'))->once();
    }

    /** One API: the earlier route is gone. */
    #[Test]
    public function the_earlier_route_is_gone(): void
    {
        $this->actingAsUser($this->makePartnerAdmin($this->makeHostPartner()));

        $this->getJson('/api/v1/lender-product-categories')->assertNotFound();
    }

    #[Test]
    public function the_embed_form_offers_the_same_categories(): void
    {
        $config = EmbedFormConfig::create([
            'partner_id' => $this->makeHostPartner()->id,
            'public_key' => EmbedFormConfig::generatePublicKey(),
            'form_title' => 'Funding Application',
            'is_active' => true,
        ]);

        $this->get('/form/' . $config->public_key)
            ->assertOk()
            ->assertSee('const FUNDING_TYPES = ["Business Line of Credit","Term Loan"]', false)
            ->assertDontSee('Old Product');
    }

    private function category(string $name, int $sortOrder, string $status = 'Active'): int
    {
        return DB::table('lender_product_categories')->insertGetId([
            'name' => $name,
            'status' => $status,
            'sort_order' => $sortOrder,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
