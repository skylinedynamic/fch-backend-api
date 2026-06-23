<?php

namespace App\Services\Borrower\CapitalAccess;

use App\Models\Common\ScoreConfiguration;
use Illuminate\Support\Collection;

/**
 * Request-scoped singleton that loads ALL score_configurations rows once
 * and serves every subsequent tier / modifier lookup from memory.
 *
 * Register via AppServiceProvider:
 *   $this->app->singleton(ScoreConfigurationCache::class);
 *
 * Usage:
 *   $cache = app(ScoreConfigurationCache::class);
 *   $tiers = $cache->getTiersFor(ScoreConfiguration::MODEL_APPROVAL_PROBABILITY);
 */
class ScoreConfigurationCache
{
    /** All rows, loaded lazily on first access */
    private ?Collection $all = null;

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * All CATEGORY_SUBSCORE_TIER rows for a model, grouped by factor_slug.
     */
    public function getTiersFor(string $modelSlug): Collection
    {
        return $this->all()
            ->where('category', ScoreConfiguration::CATEGORY_SUBSCORE_TIER)
            ->where('model_slug', $modelSlug)
            ->groupBy('factor_slug');
    }

    /**
     * All CATEGORY_LATE_PAYMENT_MODIFIER rows for a model, keyed by factor_slug.
     */
    public function getModifiersFor(string $modelSlug): Collection
    {
        return $this->all()
            ->where('category', ScoreConfiguration::CATEGORY_LATE_PAYMENT_MODIFIER)
            ->where('model_slug', $modelSlug)
            ->keyBy('factor_slug');
    }

    /**
     * All CATEGORY_MODEL_WEIGHT rows for a model, keyed by factor_slug.
     */
    public function getWeightsFor(string $modelSlug): Collection
    {
        return $this->all()
            ->where('category', ScoreConfiguration::CATEGORY_MODEL_WEIGHT)
            ->where('model_slug', $modelSlug)
            ->keyBy('factor_slug');
    }

    /**
     * All CATEGORY_READINESS_BAND rows, ordered by sort_order.
     */
    public function getReadinessBands(): Collection
    {
        return $this->all()
            ->where('category', ScoreConfiguration::CATEGORY_READINESS_BAND)
            ->where('model_slug', ScoreConfiguration::MODEL_FUNDING_READINESS)
            ->sortBy('sort_order')
            ->values();
    }

    /**
     * All rows for a model + category, no further grouping.
     */
    public function getAllFor(string $modelSlug, string $category): Collection
    {
        return $this->all()
            ->where('category', $category)
            ->where('model_slug', $modelSlug);
    }

    /**
     * Force a fresh load (e.g. after config is changed in tests).
     */
    public function flush(): void
    {
        $this->all = null;
    }

    // -------------------------------------------------------------------------
    // Internal
    // -------------------------------------------------------------------------

    private function all(): Collection
    {
        if ($this->all === null) {
            $this->all = ScoreConfiguration::all();
        }
        return $this->all;
    }
}
