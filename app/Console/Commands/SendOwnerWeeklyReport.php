<?php

namespace App\Console\Commands;

use App\Services\FCH\Common\OwnerSummary\OwnerWeeklyReportService;
use Illuminate\Console\Command;
use RuntimeException;

class SendOwnerWeeklyReport extends Command
{
    protected $signature = 'owner-summary:send-weekly-report';

    protected $description = 'Email the host owner\'s report of last week to the recipients in Report settings';

    public function handle(OwnerWeeklyReportService $report): int
    {
        try {
            $sent = $report->send();
        } catch (RuntimeException $e) {
            // A failed exit is what tells the on-call list (routes/console.php).
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info(__($sent ? 'owner_summary.report_sent' : 'owner_summary.report_off'));

        return self::SUCCESS;
    }
}
