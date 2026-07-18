<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Services\Monitoring\PrometheusStats;
use Illuminate\Support\Facades\Cache;
use Prometheus\CollectorRegistry;
use Prometheus\RenderTextFormat;
use Prometheus\Storage\InMemory;

class PrometheusController extends Controller
{
    /**
     * Expose metrics in Prometheus text format.
     *
     * Prometheus scrapes can be very frequent. Cache the expensive shell-based
     * system metrics for 60 seconds so scrapes do not hammer the server.
     */
    public function __invoke(PrometheusStats $stats)
    {
        // 1. Initialize Registry with In-Memory storage (metrics are calculated on the fly)
        $registry = new CollectorRegistry(new InMemory());

        // 2. Fetch System Metrics (cached)
        $system = Cache::remember('prometheus.system_metrics', 60, fn() => $stats->getMetrics());
        $this->addGauge($registry, 'system_cpu_usage', 'Ratio of CPU load (0.0 to 1.0)', $system['cpu_usage_ratio']);
        $this->addGauge($registry, 'system_memory_usage', 'Ratio of RAM consumption (0.0 to 1.0)', $system['memory_usage_ratio']);
        $this->addGauge($registry, 'system_disk_usage', 'Ratio of Disk space filled (0.0 to 1.0)', $system['disk_usage_ratio']);

        // 3. Fetch Application Metrics (cached)
        $app = Cache::remember('prometheus.app_metrics', 60, fn() => $stats->getAppMetrics());
        $this->addGauge($registry, 'app_total_users', 'Current total registered users in database', $app['total_users']);

        // 4. Render and return as text/plain
        $renderer = new RenderTextFormat();
        return response($renderer->render($registry->getMetricFamilySamples()))
            ->header('Content-Type', RenderTextFormat::MIME_TYPE);
    }

    private function addGauge(CollectorRegistry $registry, string $name, string $help, float $value)
    {
        $gauge = $registry->getOrRegisterGauge('laravel_fch_api', $name, $help);
        $gauge->set($value);
    }
}
