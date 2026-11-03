<?php

namespace App\Services\Borrower\CreditProfile;

use App\Models\ActivityLog\ActivityLog;
use App\Models\Borrower\Borrower;
use App\Models\Borrower\BorrowerDocument;
use App\Services\FCH\Common\ActivityLog\ActivityLogService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Removing a client's credit profile outright: the parsed report, its
 * addresses, trade lines and inquiries, the uploaded PDF and its document
 * record, and the scores copied onto the client.
 *
 * The client's credit authorisation consents are kept: they are the FCRA
 * record of what the client agreed to, not credit data.
 */
class ClientCreditProfileService
{
    /**
     * @return bool false when the client has no credit profile to remove
     */
    public function remove(Borrower $borrower, ?int $removedBy = null): bool
    {
        $profile = $borrower->creditProfile;

        if (!$profile) {
            return false;
        }

        $reportPath = $this->storedPath($profile->pdf_url);

        DB::transaction(function () use ($borrower, $profile) {
            $profile->addresses()->delete();
            $profile->trades()->delete();
            $profile->inquiries()->delete();

            if ($profile->document_id) {
                BorrowerDocument::withTrashed()->whereKey($profile->document_id)->forceDelete();
            }

            $profile->delete();

            $borrower->forceFill([
                'fico_score' => null,
                'paydex_score' => null,
                'credit_profile_updated' => false,
            ])->save();
        });

        // After the commit: a file that will not go away must not bring the
        // rows back.
        if ($reportPath !== null) {
            Storage::disk('public')->delete($reportPath);
        }

        // The client's portal re-reads its profile on the next request.
        $borrower->user?->update(['is_refresh' => true]);

        Log::info(__('borrower.logs.credit_profile_removed'), [
            'borrower_id' => $borrower->id,
            'removed_by' => $removedBy,
        ]);

        $this->recordActivity(
            $borrower,
            ActivityLog::LOG_TYPE_CREDIT_PROFILE_DELETE,
            __('borrower.activity.credit_profile_removed'),
            __('borrower.activity.credit_profile_removed_desc')
        );

        return true;
    }

    /** A saved credit profile, in the client's Activity panel (BUG-075). */
    public function recordSaved(Borrower $borrower): void
    {
        $this->recordActivity(
            $borrower,
            ActivityLog::LOG_TYPE_CREDIT_PROFILE_SAVE,
            __('borrower.activity.credit_profile_saved'),
            __('borrower.activity.credit_profile_saved_desc')
        );
    }

    /**
     * Who saved or removed a client's credit profile, and when: an internal
     * entry (credit report data is for the host's team only) on the client's
     * Activity panel. The entry names no scores or report contents.
     *
     * Called after the change has committed and in its own try/catch: a log
     * that will not write must never undo or fail the change itself.
     */
    private function recordActivity(Borrower $borrower, string $logType, string $subject, string $description): void
    {
        try {
            app(ActivityLogService::class)->logModelEvent(
                $borrower,
                $logType,
                ['category' => 'credit_profile', 'no_image' => true],
                $subject,
                $description,
                ActivityLog::VISIBILITY_INTERNAL
            );
        } catch (\Throwable $e) {
            Log::error(__('borrower.logs.credit_profile_activity_failed'), [
                'borrower_id' => $borrower->id,
                'log_type' => $logType,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /** The report's path on the public disk, from the URL it was saved under. */
    private function storedPath(?string $pdfUrl): ?string
    {
        if (!$pdfUrl || !str_contains($pdfUrl, '/storage/')) {
            return null;
        }

        return substr($pdfUrl, strpos($pdfUrl, '/storage/') + strlen('/storage/'));
    }
}
