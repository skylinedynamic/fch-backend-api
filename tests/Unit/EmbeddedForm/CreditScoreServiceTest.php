<?php

namespace Tests\Unit\EmbeddedForm;

use App\Services\FCH\Common\Scoring\CreditScoreService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Credit score banding.
 *
 * The band table is the fix for scores 670-739 being labelled "Fair" when FICO
 * calls that range "Good", so the boundary cases are the point of this file.
 *
 * The table comes from config and nowhere else, so these tests read the real
 * shipped thresholds - a boundary changed in config fails here first.
 */
#[CoversClass(CreditScoreService::class)]
class CreditScoreServiceTest extends TestCase
{
    private CreditScoreService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new CreditScoreService();
    }

    /**
     * Every boundary named in the bug report, plus the ends of the scale.
     */
    public static function boundaries(): array
    {
        return [
            'floor of the scale' => [300, 'Poor'],
            'top of Poor' => [579, 'Poor'],
            'bottom of Fair' => [580, 'Fair'],
            'top of Fair' => [669, 'Fair'],
            'bottom of Good' => [670, 'Good'],
            'reported as Fair, is Good (710)' => [710, 'Good'],
            'reported as Fair, is Good (720)' => [720, 'Good'],
            'top of Good' => [739, 'Good'],
            'bottom of Very Good' => [740, 'Very Good'],
            'top of Very Good' => [799, 'Very Good'],
            'bottom of Exceptional' => [800, 'Exceptional'],
            'ceiling of the scale' => [850, 'Exceptional'],
        ];
    }

    #[Test]
    #[DataProvider('boundaries')]
    public function it_bands_a_score_at_every_boundary(int $score, string $expected): void
    {
        $this->assertSame($expected, $this->service->label($score));
    }

    #[Test]
    public function it_reads_a_numeric_string_the_same_as_an_integer(): void
    {
        $this->assertSame('Good', $this->service->label('720'));
        $this->assertSame($this->service->label(720), $this->service->label('720'));
    }

    /**
     * The portal stores ranges, not numbers, and both must band identically.
     */
    public static function legacyRanges(): array
    {
        return [
            ['Below 600', 'Fair', 599],
            ['600-649', 'Fair', 600],
            ['650-699', 'Fair', 650],
            ['700-749', 'Good', 700],
            ['750+', 'Very Good', 750],
        ];
    }

    #[Test]
    #[DataProvider('legacyRanges')]
    public function it_bands_the_portals_legacy_range_strings(string $range, string $expected, int $normalised): void
    {
        $this->assertSame($normalised, $this->service->normalize($range));
        $this->assertSame($expected, $this->service->label($range));
    }

    #[Test]
    public function it_returns_no_band_when_there_is_no_score(): void
    {
        foreach ([null, '', false] as $empty) {
            $this->assertNull($this->service->normalize($empty));
            $this->assertNull($this->service->band($empty));
            $this->assertNull($this->service->label($empty));
        }
    }

    #[Test]
    public function it_returns_no_band_for_a_score_outside_every_range(): void
    {
        // A wrong band is worse than none: an out-of-scale value means the data
        // is bad, and labelling it anyway would hide that.
        $this->assertNull($this->service->band(0));
        $this->assertNull($this->service->band(9999));
    }

    #[Test]
    public function it_returns_no_band_for_text_that_holds_no_number(): void
    {
        $this->assertNull($this->service->normalize('not a score'));
        $this->assertNull($this->service->band('not a score'));
    }

    #[Test]
    public function it_exposes_a_key_and_tone_for_rendering(): void
    {
        $band = $this->service->band(720);

        $this->assertSame('good', $band['key']);
        $this->assertSame('Good', $band['label']);
        $this->assertSame(670, $band['min']);
        $this->assertSame(739, $band['max']);
        $this->assertArrayHasKey('tone', $band);
    }

    #[Test]
    public function the_thresholds_come_from_config_and_are_not_hard_coded(): void
    {
        // Rewriting the table in config must change the answer. If this passes
        // with the defaults still applied, a threshold has been baked into the
        // service - which is the whole class of bug this file exists for.
        config(['funding_application.credit_score.bands' => [
            ['key' => 'low', 'label' => 'Low', 'min' => 300, 'max' => 699, 'tone' => 'danger'],
            ['key' => 'high', 'label' => 'High', 'min' => 700, 'max' => 850, 'tone' => 'success'],
        ]]);

        $this->assertSame('Low', $this->service->label(699));
        $this->assertSame('High', $this->service->label(700));
        // 720 is "Good" under the shipped table.
        $this->assertSame('High', $this->service->label(720));
    }

    #[Test]
    public function the_shipped_table_is_the_documented_fico_scale(): void
    {
        $labels = array_column($this->service->bands(), 'label');

        $this->assertSame(
            ['Poor', 'Fair', 'Good', 'Very Good', 'Exceptional'],
            $labels
        );
    }

    #[Test]
    public function the_shipped_bands_leave_no_gap_and_no_overlap(): void
    {
        // A gap would silently produce an unlabelled score; an overlap would
        // make the answer depend on table order.
        $bands = $this->service->bands();

        $this->assertSame(
            (int) config('funding_application.credit_score.min'),
            (int) $bands[0]['min']
        );
        $this->assertSame(
            (int) config('funding_application.credit_score.max'),
            (int) $bands[count($bands) - 1]['max']
        );

        for ($i = 1; $i < count($bands); $i++) {
            $this->assertSame(
                (int) $bands[$i - 1]['max'] + 1,
                (int) $bands[$i]['min'],
                "band '{$bands[$i]['label']}' must start where '{$bands[$i - 1]['label']}' ends"
            );
        }
    }

    #[Test]
    public function to_array_reports_the_scale_and_the_business_score_options(): void
    {
        $payload = $this->service->toArray();

        $this->assertSame(300, $payload['min']);
        $this->assertSame(850, $payload['max']);
        $this->assertCount(5, $payload['bands']);
        $this->assertContains('Not established yet', $payload['business_score_options']);
    }
}
