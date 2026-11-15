<?php

namespace App\Console\Commands;

use App\Services\FCH\Common\OwnerSummary\OwnerTelegramSummaryService;
use Illuminate\Console\Command;
use RuntimeException;

class SendOwnerTelegramSummary extends Command
{
    protected $signature = 'owner-summary:send-telegram-summary {period : week (last week) or month (last month)}';

    protected $description = 'Send the host owner the headline numbers of last week or last month on Telegram';

    public function handle(OwnerTelegramSummaryService $summary): int
    {
        $period = (string) $this->argument('period');
        if (!array_key_exists($period, OwnerTelegramSummaryService::PERIODS)) {
            $this->error(__('owner_summary.telegram.bad_period', ['periods' => implode(', ', array_keys(OwnerTelegramSummaryService::PERIODS))]));

            return self::FAILURE;
        }

        try {
            $sent = $summary->send($period);
        } catch (RuntimeException $e) {
            // A failed exit is what tells the on-call list (routes/console.php).
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info(__($sent ? 'owner_summary.telegram.sent' : 'owner_summary.telegram.off'));

        return self::SUCCESS;
    }
}
