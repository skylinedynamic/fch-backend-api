<?php

namespace App\Console\Commands;

use App\Models\Audit\FundingApplicationAuditEvent;
use App\Services\Audit\FundingApplicationAuditTrail;
use Illuminate\Console\Command;

/** Walk the audit trail's hash chains; fails when any is broken. */
class VerifyAuditTrail extends Command
{
    protected $signature = 'audit:verify {--application= : Only this funding application}';

    protected $description = 'Check every funding application audit trail chain for a changed or removed event';

    public function handle(FundingApplicationAuditTrail $trail): int
    {
        $ids = $this->option('application')
            ? [(int) $this->option('application')]
            : FundingApplicationAuditEvent::distinct()->orderBy('funding_application_id')->pluck('funding_application_id')->all();

        $broken = 0;
        foreach ($ids as $id) {
            $result = $trail->verify((int) $id);
            if ($result['valid']) {
                $this->line(__('audit.verify.valid', ['id' => $id, 'count' => $result['checked']]));
            } else {
                $broken++;
                $this->error(__('audit.verify.broken', ['id' => $id, 'event' => $result['broken_at']]));
            }
        }

        return $broken === 0 ? self::SUCCESS : self::FAILURE;
    }
}
