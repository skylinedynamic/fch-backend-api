<?php

namespace App\Console\Commands;

use App\Models\Audit\FundingApplicationAuditEvent;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * The day's seal of the audit trail: for each application, its event count
 * and the hash of its last event, written to {audit folder}/seals/{date}.json.
 * Kept outside the database, a seal shows an event later changed or removed
 * even if someone rewrote the whole chain in the database after it.
 */
class SealAuditTrail extends Command
{
    protected $signature = 'audit:seal';

    protected $description = 'Write today\'s seal of the funding application audit trail (last hash per application)';

    public function handle(): int
    {
        $latest = FundingApplicationAuditEvent::selectRaw('funding_application_id, MAX(id) as last_id, COUNT(*) as events')
            ->groupBy('funding_application_id')
            ->orderBy('funding_application_id')
            ->get();
        $hashes = FundingApplicationAuditEvent::whereIn('id', $latest->pluck('last_id'))->pluck('hash', 'id');

        $seal = [
            'sealed_at' => now('UTC')->toIso8601ZuluString(),
            'applications' => $latest->map(fn ($row) => [
                'funding_application_id' => (int) $row->funding_application_id,
                'events' => (int) $row->events,
                'last_event_id' => (int) $row->last_id,
                'last_hash' => $hashes[$row->last_id] ?? null,
            ])->values()->all(),
        ];

        $path = trim(config('audit.folder', 'audit'), '/') . '/seals/' . now('UTC')->format('Y-m-d') . '.json';
        Storage::disk(config('audit.disk', 'public'))->put($path, json_encode($seal, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $this->info(__('audit.seal.written', ['count' => count($seal['applications']), 'path' => $path]));

        return self::SUCCESS;
    }
}
