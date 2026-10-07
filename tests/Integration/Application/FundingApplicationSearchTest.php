<?php

namespace Tests\Integration\Application;

use App\Http\Resources\FCH\Common\Application\FundingApplicationResource;
use App\Models\Borrower\Borrower;
use App\Models\Borrower\FundingApplication;
use App\Models\Partner\Partner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The Funding Applications list search (FundingApplication::scopeSearch) and
 * the fields its details page reads. Runs against the real schema, so a
 * searched column that does not exist fails here instead of as a 500 behind
 * the list's search box.
 */
class FundingApplicationSearchTest extends TestCase
{
    use RefreshDatabase;

    private function application(array $attributes, array $borrowerAttributes = []): FundingApplication
    {
        $partner = Partner::create([
            'is_host' => false,
            'status' => Partner::STATUS_ACTIVE,
            'company_name' => 'Search Test Partner ' . uniqid(),
            'full_name' => 'Search Test Partner',
            'email' => 'partner-' . uniqid() . '@example.test',
        ]);

        $borrower = Borrower::create(array_merge([
            'partner_id' => $partner->id,
            'status' => Borrower::STATUS_ACTIVE,
            'first_name' => 'Test',
            'last_name' => 'Borrower',
            'email' => 'borrower-' . uniqid() . '@example.test',
        ], $borrowerAttributes));

        return FundingApplication::forceCreate(array_merge([
            'partner_id' => $partner->id,
            'borrower_id' => $borrower->id,
        ], $attributes));
    }

    #[Test]
    public function searching_a_borrower_name_returns_only_the_matching_applications(): void
    {
        $match = $this->application(['full_name' => 'Zachariah Quillfeather', 'first_name' => 'Zachariah', 'last_name' => 'Quillfeather']);
        $other = $this->application(['full_name' => 'Odessa Mccal', 'first_name' => 'Odessa', 'last_name' => 'Mccal']);

        $found = FundingApplication::search('Zachariah Quillfeather')->pluck('id')->all();

        $this->assertContains($match->id, $found);
        $this->assertNotContains($other->id, $found);
    }

    #[Test]
    public function the_borrowers_own_first_middle_and_last_name_are_searched(): void
    {
        // The application carries no name of its own; only the borrower does.
        $match = $this->application(
            ['full_name' => null, 'first_name' => null, 'last_name' => null],
            ['first_name' => 'Ignatius', 'middle_name' => 'Bartholomew', 'last_name' => 'Wolcott']
        );
        $other = $this->application(['full_name' => 'Odessa Mccal'], ['first_name' => 'Odessa', 'last_name' => 'Mccal']);

        foreach (['Ignatius', 'Bartholomew', 'Wolcott', 'Ignatius Wolcott', 'Ignatius Bartholomew Wolcott', 'wolcott ignatius'] as $term) {
            $found = FundingApplication::search($term)->pluck('id')->all();

            $this->assertContains($match->id, $found, "searching \"{$term}\"");
            $this->assertNotContains($other->id, $found, "searching \"{$term}\"");
        }

        // Every word has to match: a wrong last name finds nobody.
        $this->assertSame([], FundingApplication::search('Ignatius Mccal')->pluck('id')->all());
    }

    #[Test]
    public function the_applicants_split_name_on_the_application_is_matched_word_by_word(): void
    {
        $match = $this->application(['full_name' => null, 'first_name' => 'Perpetua', 'middle_name' => 'Jane', 'last_name' => 'Okonkwo']);

        $this->assertContains($match->id, FundingApplication::search('Perpetua Okonkwo')->pluck('id')->all());
        $this->assertContains($match->id, FundingApplication::search('Jane')->pluck('id')->all());
    }

    #[Test]
    public function the_columns_shown_in_the_list_are_searched(): void
    {
        $match = $this->application([
            'full_name' => 'Listed Columns',
            'industry_type' => 'Zeppelin Manufacturing',
            'entity_type' => 'Quasi-Cooperative',
            'status' => FundingApplication::MATCHED_TO_LENDER,
        ], ['email' => 'unique-borrower-mail@example.test']);

        // The application number is generated when the application is created.
        foreach ([$match->fresh()->application_number, 'Zeppelin', 'Quasi-Cooperative', 'Matched to Lender', 'unique-borrower-mail'] as $term) {
            $this->assertContains($match->id, FundingApplication::search($term)->pluck('id')->all(), "searching \"{$term}\"");
        }
    }

    #[Test]
    public function a_business_postal_code_can_be_searched(): void
    {
        $match = $this->application(['full_name' => 'Postal Match', 'business_postal_code' => '99887-7766']);

        $this->assertContains($match->id, FundingApplication::search('99887-7766')->pluck('id')->all());
    }

    #[Test]
    public function the_details_payload_says_whether_the_application_can_still_be_edited(): void
    {
        $open = $this->application(['full_name' => 'Open', 'status' => FundingApplication::PENDING]);
        $locked = $this->application(['full_name' => 'Locked', 'status' => FundingApplication::MATCHED_TO_LENDER]);

        $payload = fn (FundingApplication $application) => (new FundingApplicationResource(
            $application->load(['borrower', 'partner', 'commission', 'coborrowers', 'fundingDocuments', 'downloadHistories', 'fundingSequence', 'fundingRequest'])
        ))->toArray(request());

        $this->assertTrue($payload($open)['is_editable']);
        $this->assertFalse($payload($locked)['is_editable']);
        $this->assertArrayHasKey('funding_request_id', $payload($open));
    }
}
