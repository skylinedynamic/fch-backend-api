<?php

namespace App\Services\FCH\Common\OwnerSummary;

use App\Models\Partner\Partner;
use App\Services\Telegram\TelegramClient;
use RuntimeException;

/**
 * Sends the host owner the headline numbers of last week or last month to one
 * Telegram chat (TELEGRAM_OWNER_CHAT_ID), live payments, Eastern time - the same
 * numbers as the Owner Summary screen and the email report.
 */
class OwnerTelegramSummaryService
{
    public const PERIOD_WEEK = 'week';
    public const PERIOD_MONTH = 'month';

    /** What each period sends, as an OwnerSummaryPeriod key. */
    public const PERIODS = [
        self::PERIOD_WEEK => OwnerSummaryPeriod::LAST_WEEK,
        self::PERIOD_MONTH => OwnerSummaryPeriod::LAST_MONTH,
    ];

    public function __construct(
        private readonly OwnerSummaryService $summary,
        private readonly TelegramClient $telegram,
    ) {
    }

    /**
     * @param string $period one of PERIODS' keys
     *
     * @return bool whether a summary went out; not being set up is a normal reason for it not to
     *
     * @throws RuntimeException when a summary is due but cannot be built or sent
     */
    public function send(string $period): bool
    {
        $chatId = (string) config('services.telegram.owner_chat_id');
        if (!$this->telegram->isConfigured() || $chatId === '') {
            return false;
        }

        $host = Partner::host();
        $owner = $host?->partnerUser;
        if (!$owner) {
            throw new RuntimeException(__('owner_summary.telegram.no_owner'));
        }

        $summary = $this->summary->summary(
            $owner,
            OwnerSummaryPeriod::make(self::PERIODS[$period], OwnerWeeklyReportService::TIMEZONE),
            OwnerSummaryService::ENVIRONMENT_PRODUCTION
        );

        $this->telegram->sendMessage($chatId, OwnerTelegramMessage::make($summary, $host->company_name ?: config('app.name')));

        return true;
    }
}
