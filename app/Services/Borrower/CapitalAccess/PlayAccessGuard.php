<?php

namespace App\Services\Borrower\CapitalAccess;

use App\Models\CreditBuilder\BorrowerCapitalAccessPlay;
use App\Services\Borrower\CapitalAccess\ScoreConfigurationCache;

class PlayAccessGuard
{
    protected static $runningPlay = [];
    protected static $isDelayActive = [];

    public static function getRunningPlay(int $borrowerId): ?BorrowerCapitalAccessPlay
    {
        if (!array_key_exists($borrowerId, self::$runningPlay)) {
            self::$runningPlay[$borrowerId] = BorrowerCapitalAccessPlay::where('borrower_id', $borrowerId)
                ->where('status', BorrowerCapitalAccessPlay::STATUS_RUNNING)
                ->first();
        }
        return self::$runningPlay[$borrowerId];
    }

    public static function isDelayActive(int $borrowerId): bool
    {
        if (!array_key_exists($borrowerId, self::$isDelayActive)) {
            $isDelayActive = false;
            $delayDays = app(ScoreConfigurationCache::class)->getNextPlayDelayDays();
            if ($delayDays > 0) {
                $latestCompleted = BorrowerCapitalAccessPlay::where('borrower_id', $borrowerId)
                    ->where('status', BorrowerCapitalAccessPlay::STATUS_COMPLETED)
                    ->orderBy('updated_at', 'desc')
                    ->first();
                if ($latestCompleted) {
                    $calcUnlockAt = $latestCompleted->updated_at->copy()->addDays($delayDays);
                    if (now()->lessThan($calcUnlockAt)) {
                        $isDelayActive = true;
                    }
                }
            }
            self::$isDelayActive[$borrowerId] = $isDelayActive;
        }
        return self::$isDelayActive[$borrowerId];
    }
}
