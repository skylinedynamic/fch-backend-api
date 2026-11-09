<?php

namespace App\Services\FCH\Common\OwnerSummary;

use App\Mail\OwnerSummary\OwnerWeeklyReportMail;
use App\Models\OwnerSummary\OwnerReportSetting;
use App\Models\Partner\Partner;
use App\Services\FCH\Common\Notification\Notifier;
use RuntimeException;

/**
 * Sends the host owner's report of last week: live payments, Sunday to Saturday
 * Eastern time. The numbers are the Owner Summary screen's, from the same service.
 */
class OwnerWeeklyReportService
{
    public const TIMEZONE = 'America/New_York';

    public const NOTIFICATION_TYPE = 'owner_summary.weekly_report';

    public function __construct(
        private readonly OwnerSummaryService $summary,
        private readonly OwnerWeeklyReportPdf $pdf,
        private readonly Notifier $notifier,
    ) {
    }

    /**
     * Every call sends: how often it runs is up to the cron job or scheduler.
     * last_sent_period_from is only a record of the last week sent.
     *
     * @return bool whether a report went out; off and nobody on the list are
     *              normal reasons for it not to
     *
     * @throws RuntimeException when a report is due but cannot be built
     */
    public function send(): bool
    {
        $host = Partner::host();
        $setting = $host ? OwnerReportSetting::where('partner_id', $host->id)->first() : null;

        if (!$setting?->is_enabled || empty($setting->recipients)) {
            return false;
        }

        $period = OwnerSummaryPeriod::make(OwnerSummaryPeriod::LAST_WEEK, self::TIMEZONE);

        $owner = $host->partnerUser;
        if (!$owner) {
            throw new RuntimeException(__('owner_summary.report_no_owner'));
        }

        $report = $this->summary->summary($owner, $period, OwnerSummaryService::ENVIRONMENT_PRODUCTION);

        $this->notifier->send(
            self::NOTIFICATION_TYPE,
            mail: (new OwnerWeeklyReportMail($report, $this->pdf->render($report), $host->id))->to($setting->recipients)
        );

        $setting->update(['last_sent_period_from' => $period->from->toDateString()]);

        return true;
    }
}
