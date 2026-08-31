<?php

namespace Tests\Unit\Services\Borrower\CapitalAccess;

use App\Models\Borrower\Borrower;
use App\Models\Borrower\BorrowerBankAccount;
use App\Models\Borrower\BorrowerCreditProfile;
use App\Models\Common\ScoreConfiguration;
use App\Services\Borrower\CapitalAccess\ReadinessScoreCalculationService;
use Illuminate\Database\Eloquent\Factories\Factory;
use Tests\TestCase;

class ReadinessScoreCalculationServiceTest extends TestCase
{
    protected ReadinessScoreCalculationService $service;

    protected function setUp(): void
    {
        parent::setUp();

        Factory::guessFactoryNamesUsing(
            fn (string $modelName) => 'Database\\Factories\\'.class_basename($modelName).'Factory'
        );

        $this->service = new ReadinessScoreCalculationService;
    }

    public function test_returns_zero_score_and_not_ready_band_when_no_score_configuration_exists(): void
    {
        $borrower = Borrower::factory()->create();

        $result = $this->service->calculate($borrower);

        $this->assertSame(0, $result['total_score']);
        $this->assertSame('Not Ready', $result['score_label']);
        $this->assertSame('#6c757d', $result['score_label_color']);
        $this->assertCount(6, $result['factors']);

        foreach ($result['factors'] as $factor) {
            $this->assertSame(0.0, $factor['weighted_score']);
        }
    }

    public function test_fico_subscore_is_weighted_by_the_configured_model_weight_and_matching_tier(): void
    {
        ScoreConfiguration::create([
            'category' => ScoreConfiguration::CATEGORY_MODEL_WEIGHT,
            'model_slug' => ScoreConfiguration::MODEL_FUNDING_READINESS,
            'factor_slug' => ScoreConfiguration::FACTOR_FICO,
            'numeric_value' => 20,
        ]);

        ScoreConfiguration::create([
            'category' => ScoreConfiguration::CATEGORY_SUBSCORE_TIER,
            'model_slug' => ScoreConfiguration::MODEL_FUNDING_READINESS,
            'factor_slug' => ScoreConfiguration::FACTOR_FICO,
            'label' => 'Good',
            'min_value' => 700,
            'max_value' => 749,
            'numeric_value' => 80,
        ]);

        $borrower = Borrower::factory()->create();
        BorrowerCreditProfile::create([
            'borrower_id' => $borrower->id,
            'fico_score' => 720,
        ]);

        $result = $this->service->calculate($borrower->fresh());

        $ficoFactor = collect($result['factors'])->firstWhere('slug', ScoreConfiguration::FACTOR_FICO);

        $this->assertNotNull($ficoFactor);
        $this->assertSame(720, $ficoFactor['value']);
        // 80 (tier value) * (20 / 100) weight = 16.0
        $this->assertSame(16.0, $ficoFactor['weighted_score']);
        // raw_score reverses the weighting back out: 16 / (20/100) = 80
        $this->assertSame(80.0, $ficoFactor['raw_score']);
        $this->assertSame(16, $result['total_score']);
    }

    public function test_fico_falls_back_to_borrower_level_fico_score_when_no_credit_profile_exists(): void
    {
        ScoreConfiguration::create([
            'category' => ScoreConfiguration::CATEGORY_MODEL_WEIGHT,
            'model_slug' => ScoreConfiguration::MODEL_FUNDING_READINESS,
            'factor_slug' => ScoreConfiguration::FACTOR_FICO,
            'numeric_value' => 10,
        ]);
        ScoreConfiguration::create([
            'category' => ScoreConfiguration::CATEGORY_SUBSCORE_TIER,
            'model_slug' => ScoreConfiguration::MODEL_FUNDING_READINESS,
            'factor_slug' => ScoreConfiguration::FACTOR_FICO,
            'label' => 'Fair',
            'min_value' => 600,
            'max_value' => 699,
            'numeric_value' => 50,
        ]);

        $borrower = Borrower::factory()->create(['fico_score' => 650]);

        $result = $this->service->calculate($borrower);

        $ficoFactor = collect($result['factors'])->firstWhere('slug', ScoreConfiguration::FACTOR_FICO);

        $this->assertSame(650, $ficoFactor['value']);
        $this->assertSame(5.0, $ficoFactor['weighted_score']); // 50 * 0.10
    }

    public function test_total_score_is_selected_by_the_matching_readiness_band_in_sort_order(): void
    {
        ScoreConfiguration::create([
            'category' => ScoreConfiguration::CATEGORY_MODEL_WEIGHT,
            'model_slug' => ScoreConfiguration::MODEL_FUNDING_READINESS,
            'factor_slug' => ScoreConfiguration::FACTOR_FICO,
            'numeric_value' => 100,
        ]);
        ScoreConfiguration::create([
            'category' => ScoreConfiguration::CATEGORY_SUBSCORE_TIER,
            'model_slug' => ScoreConfiguration::MODEL_FUNDING_READINESS,
            'factor_slug' => ScoreConfiguration::FACTOR_FICO,
            'label' => 'Elite',
            'min_value' => 750,
            'max_value' => null,
            'numeric_value' => 90,
        ]);

        // Bands are evaluated in sort_order, first match wins — so the highest
        // threshold band must sort before lower ones.
        ScoreConfiguration::create([
            'category' => ScoreConfiguration::CATEGORY_READINESS_BAND,
            'model_slug' => ScoreConfiguration::MODEL_FUNDING_READINESS,
            'label' => ScoreConfiguration::BAND_ELITE_READY,
            'color' => '#00ff00',
            'min_value' => 85,
            'sort_order' => 1,
        ]);
        ScoreConfiguration::create([
            'category' => ScoreConfiguration::CATEGORY_READINESS_BAND,
            'model_slug' => ScoreConfiguration::MODEL_FUNDING_READINESS,
            'label' => ScoreConfiguration::BAND_NOT_READY,
            'color' => '#6c757d',
            'min_value' => 0,
            'sort_order' => 2,
        ]);

        $borrower = Borrower::factory()->create();
        BorrowerCreditProfile::create([
            'borrower_id' => $borrower->id,
            'fico_score' => 780,
        ]);

        $result = $this->service->calculate($borrower->fresh());

        $this->assertSame(90, $result['total_score']); // 90 * (100/100)
        $this->assertSame(ScoreConfiguration::BAND_ELITE_READY, $result['score_label']);
        $this->assertSame('#00ff00', $result['score_label_color']);
    }

    public function test_banking_relationships_subscore_uses_bank_account_count_and_age(): void
    {
        ScoreConfiguration::create([
            'category' => ScoreConfiguration::CATEGORY_MODEL_WEIGHT,
            'model_slug' => ScoreConfiguration::MODEL_FUNDING_READINESS,
            'factor_slug' => ScoreConfiguration::FACTOR_BANKING_RELATIONSHIPS,
            'numeric_value' => 10,
        ]);
        ScoreConfiguration::create([
            'category' => ScoreConfiguration::CATEGORY_SUBSCORE_TIER,
            'model_slug' => ScoreConfiguration::MODEL_FUNDING_READINESS,
            'factor_slug' => ScoreConfiguration::FACTOR_BANKING_RELATIONSHIPS,
            'label' => ScoreConfiguration::STATUS_BANKING_NONE,
            'numeric_value' => 0,
            'conditions' => [
                ['accounts' => ['operator' => '=', 'value' => 0]],
            ],
        ]);
        ScoreConfiguration::create([
            'category' => ScoreConfiguration::CATEGORY_SUBSCORE_TIER,
            'model_slug' => ScoreConfiguration::MODEL_FUNDING_READINESS,
            'factor_slug' => ScoreConfiguration::FACTOR_BANKING_RELATIONSHIPS,
            'label' => ScoreConfiguration::STATUS_BANKING_STRONG,
            'numeric_value' => 100,
            'conditions' => [
                ['accounts' => ['operator' => '>=', 'value' => 1]],
            ],
        ]);

        $borrowerNoAccounts = Borrower::factory()->create();
        $noAccountsResult = $this->service->calculate($borrowerNoAccounts);
        $noneFactor = collect($noAccountsResult['factors'])->firstWhere('slug', ScoreConfiguration::FACTOR_BANKING_RELATIONSHIPS);
        $this->assertSame(0.0, $noneFactor['weighted_score']);

        $borrowerWithAccount = Borrower::factory()->create();
        BorrowerBankAccount::factory()->create([
            'borrower_id' => $borrowerWithAccount->id,
            'account_open_date' => now()->subYears(2)->toDateString(),
        ]);
        $withAccountResult = $this->service->calculate($borrowerWithAccount->fresh());
        $strongFactor = collect($withAccountResult['factors'])->firstWhere('slug', ScoreConfiguration::FACTOR_BANKING_RELATIONSHIPS);
        $this->assertSame(10.0, $strongFactor['weighted_score']); // 100 * 0.10
    }
}
