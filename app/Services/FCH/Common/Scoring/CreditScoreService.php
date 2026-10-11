<?php

namespace App\Services\FCH\Common\Scoring;

/**
 * Resolves a credit score to its band.
 *
 * The one place thresholds live. Screens that render a score ask for a band here
 * (server side) or read the band the API already resolved for them (client side)
 * - none of them carry their own table. The partner portal used to hard code
 * `>= 740 good, >= 670 fair`, which labelled every score from 670 to 739 "Fair"
 * when FICO calls that range "Good".
 *
 * The table comes from config/funding_application.php and nowhere else, so a
 * score means the same thing everywhere and changing a threshold is a reviewable
 * edit rather than a row someone can quietly change in production.
 */
class CreditScoreService
{
    /**
     * The band table.
     *
     * @return array<int, array{key: string, label: string, min: int, max: int, tone: string}>
     */
    public function bands(): array
    {
        return config('funding_application.credit_score.bands', []);
    }

    /**
     * Normalise whatever a score arrives as into a number, or null.
     *
     * A score reaches us three ways: as an integer from the embedded form, as a
     * numeric string from the database, or as one of the portal's legacy range
     * strings ("700-749", "750+", "Below 600"). A range is represented by its
     * lower bound, except "Below X", which has no lower bound of its own and is
     * represented by the value just under X.
     */
    public function normalize($score): ?int
    {
        if ($score === null || $score === '' || is_bool($score)) {
            return null;
        }

        if (is_numeric($score)) {
            return (int) $score;
        }

        $value = trim((string) $score);

        if (preg_match('/below\s*(\d+)/i', $value, $m)) {
            return max((int) $m[1] - 1, (int) config('funding_application.credit_score.min', 300));
        }

        if (preg_match('/(\d+)/', $value, $m)) {
            return (int) $m[1];
        }

        return null;
    }

    /**
     * The band a score falls in, or null when there is no score.
     *
     * @return array{key: string, label: string, min: int, max: int, tone: string}|null
     */
    public function band($score): ?array
    {
        $value = $this->normalize($score);

        if ($value === null) {
            return null;
        }

        foreach ($this->bands() as $band) {
            if ($value >= (int) $band['min'] && $value <= (int) $band['max']) {
                return [
                    'key' => $band['key'] ?? str($band['label'])->slug('_')->value(),
                    'label' => $band['label'],
                    'min' => (int) $band['min'],
                    'max' => (int) $band['max'],
                    'tone' => $band['tone'] ?? 'secondary',
                ];
            }
        }

        // A score outside every band - a gap in the table, or a value beyond
        // the configured range - gets no band rather than a wrong one.
        return null;
    }

    public function label($score): ?string
    {
        return $this->band($score)['label'] ?? null;
    }

    public function key($score): ?string
    {
        return $this->band($score)['key'] ?? null;
    }

    /**
     * The table plus its bounds, for any client that renders the scale itself -
     * the embedded form shows the band live as the applicant types.
     */
    public function toArray(): array
    {
        return [
            'min' => (int) config('funding_application.credit_score.min', 300),
            'max' => (int) config('funding_application.credit_score.max', 850),
            'bands' => $this->bands(),
            // Business credit is a separate self-reported scale and is never
            // run through the bands above.
            'business_score_options' => config('funding_application.credit_score.business_score_options', []),
        ];
    }
}
