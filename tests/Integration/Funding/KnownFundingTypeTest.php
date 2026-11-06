<?php

namespace Tests\Integration\Funding;

use App\Http\Requests\Api\V1\Borrower\FundApplication\StoreFundingRequestRequest as BorrowerStoreFundingRequestRequest;
use App\Http\Requests\Api\V1\FCH\Partner\FundApplication\StoreFundingRequestRequest as PartnerStoreFundingRequestRequest;
use App\Http\Requests\Api\V1\Global\Website\EmbedForm\SubmitEmbedFormRequest;
use App\Models\Lender\LenderProductCategory;
use App\Rules\KnownFundingType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Preferred and Additional Funding Types are lender product categories
 * (Lenders > Product Categories) that are Active - exactly the list the wizards
 * and the embed form offer, nothing else (2026-11-05: the old hard-coded names
 * such as "Line of Credit" are no longer accepted).
 */
#[CoversClass(KnownFundingType::class)]
#[CoversClass(LenderProductCategory::class)]
class KnownFundingTypeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['Business Line of Credit' => 'Active', 'Term Loan' => 'Active', 'Old Product' => 'Inactive'] as $name => $status) {
            DB::table('lender_product_categories')->insert(['name' => $name, 'status' => $status, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    /** @return array<string, array{mixed, bool}> */
    public static function values(): array
    {
        return [
            'an active category' => ['Business Line of Credit', true],
            'a name from the old hard-coded list' => ['Line of Credit', false],
            'a category in other letter case' => ['business line of credit', false],
            'an inactive category' => ['Old Product', false],
            'anything else' => ['Something Else', false],
            'not a string' => [['Term Loan'], false],
        ];
    }

    #[Test]
    #[DataProvider('values')]
    public function an_additional_funding_type_must_be_a_known_one(mixed $value, bool $passes): void
    {
        $this->assertSame($passes, Validator::make(['type' => $value], ['type' => [new KnownFundingType]])->passes());
    }

    #[Test]
    public function the_active_categories_are_the_funding_types_in_their_order(): void
    {
        DB::table('lender_product_categories')->where('name', 'Term Loan')->update(['sort_order' => 1]);
        DB::table('lender_product_categories')->where('name', 'Business Line of Credit')->update(['sort_order' => 2]);

        $this->assertSame(['Term Loan', 'Business Line of Credit'], LenderProductCategory::fundingTypeNames());
    }

    /** @return array<string, array{class-string}> */
    public static function requests(): array
    {
        return [
            'borrower wizard' => [BorrowerStoreFundingRequestRequest::class],
            'partner / admin wizard' => [PartnerStoreFundingRequestRequest::class],
        ];
    }

    /** Moved from Unit/EmbeddedForm/SubmitEmbedFormRequestTest: the checks now read the categories. */
    #[Test]
    public function the_embed_form_takes_funding_types_from_the_list(): void
    {
        DB::table('lender_product_categories')->insert(['name' => 'SBA Financing', 'status' => 'Active', 'created_at' => now(), 'updated_at' => now()]);
        $rules = (fn () => $this->fundingRequestRules())->call(new SubmitEmbedFormRequest);
        $rules = array_intersect_key($rules, array_flip(['preferred_funding_type', 'additional_funding_types', 'additional_funding_types.*']));

        $valid = fn (?array $types) => Validator::make(array_filter(['preferred_funding_type' => 'Term Loan', 'additional_funding_types' => $types], fn ($v) => $v !== null), $rules)->passes();

        // Optional.
        $this->assertTrue($valid(null));
        $this->assertTrue($valid([]));
        // Several at once.
        $this->assertTrue($valid(['Business Line of Credit', 'SBA Financing']));
        // Only from the list.
        $this->assertFalse($valid(['Something Else']));
        // Each once.
        $this->assertFalse($valid(['Business Line of Credit', 'Business Line of Credit']));
        // Never the preferred one again.
        $this->assertFalse($valid(['Business Line of Credit', 'Term Loan']));
    }

    #[Test]
    #[DataProvider('requests')]
    public function the_wizards_take_an_additional_type_from_the_list(string $request): void
    {
        $rule = (new $request)->rules()['additional_funding_types.*'];

        $this->assertTrue(Validator::make(['preferred_funding_type' => 'Term Loan', 'additional_funding_types' => ['Business Line of Credit']], ['additional_funding_types.*' => $rule])->passes());
        $this->assertFalse(Validator::make(['preferred_funding_type' => 'Term Loan', 'additional_funding_types' => ['Old Product']], ['additional_funding_types.*' => $rule])->passes());
    }

    /**
     * The Preferred Funding Type comes from the same list: an Active category,
     * nothing else - not an old hard-coded name, not a funding sequence name.
     */
    #[Test]
    public function the_preferred_type_is_an_active_category_too(): void
    {
        $sets = [
            'borrower wizard' => (new BorrowerStoreFundingRequestRequest)->rules(),
            'partner / admin wizard' => (new PartnerStoreFundingRequestRequest)->rules(),
            'embed form' => (fn () => $this->fundingRequestRules())->call(new SubmitEmbedFormRequest),
        ];

        foreach ($sets as $form => $rules) {
            $valid = fn (?string $type) => Validator::make(['preferred_funding_type' => $type], ['preferred_funding_type' => $rules['preferred_funding_type']])->passes();

            $this->assertTrue($valid('Term Loan'), $form);
            $this->assertFalse($valid('Line of Credit'), "{$form}: an old hard-coded name");
            $this->assertFalse($valid('Business Lines of Credit'), "{$form}: a funding sequence name");
            $this->assertFalse($valid('Old Product'), "{$form}: an inactive category");
            $this->assertFalse($valid(null), "{$form}: required");
        }
    }
}
