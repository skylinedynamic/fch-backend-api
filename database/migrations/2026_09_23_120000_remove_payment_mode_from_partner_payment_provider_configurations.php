<?php

use App\Models\PaymentMethod\PartnerPaymentProvider;
use App\Support\Payment\PaymentCredentials;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Log;

/**
 * Retire the duplicate `payment_mode` stored inside partner payment configurations.
 *
 * Before 2026_08_09_111000 the mode lived only inside the encrypted
 * `configuration` blob. That migration promoted it to its own column and the
 * save path has deleted the inline copy on every save since, but it backfilled
 * nothing - so rows written earlier still carry both, and rows never re-saved
 * since carry a column holding nothing but its `sandbox` default.
 *
 * Having the same fact in two places is what let readers disagree: half resolved
 * the column, half the blob, and payments were charged against the wrong Stripe
 * account for it. This removes the second copy so the column is the only answer.
 *
 * Where the two differ, the blob wins and is copied onto the column.
 *
 * A surviving blob copy means the row has not been through the save path since
 * 2026-08-09, because that path deletes the copy on every save. So the copy is
 * the last thing the partner actually chose, while the column is whatever the
 * `default('sandbox')` on the 2026_08_09 migration stamped into every existing
 * row - a value nobody selected. Reading the column there would silently demote
 * a live partner to sandbox, which is exactly the failure this whole cleanup
 * exists to end.
 *
 * Every row touched is logged with both values, and a correction is logged as a
 * warning, because this edits stored payment configuration and a silent rewrite
 * would be impossible to audit afterwards.
 */
return new class extends Migration {
    public function up(): void
    {
        $dropped = 0;
        $corrected = 0;

        PartnerPaymentProvider::query()->chunkById(100, function ($linkages) use (&$dropped, &$corrected) {
            foreach ($linkages as $linkage) {
                $configuration = $linkage->configuration;

                if (!is_array($configuration) || !array_key_exists('payment_mode', $configuration)) {
                    continue;
                }

                $columnMode = $linkage->payment_mode;

                // Normalized, so a legacy 'Production' or a stray value cannot be
                // written into the column verbatim.
                $blobMode = PaymentCredentials::normalizeMode($configuration['payment_mode']);

                unset($configuration['payment_mode']);
                $linkage->configuration = $configuration;

                if ($blobMode !== $columnMode) {
                    $linkage->payment_mode = $blobMode;
                    $corrected++;

                    Log::warning('[payment_mode cleanup] Corrected the column from the stored configuration', [
                        'partner_payment_provider_id' => $linkage->id,
                        'partner_id' => $linkage->partner_id,
                        'column_was' => $columnMode,
                        'column_now' => $blobMode,
                    ]);
                } else {
                    Log::info('[payment_mode cleanup] Dropped inline copy; column already agreed', [
                        'partner_payment_provider_id' => $linkage->id,
                        'partner_id' => $linkage->partner_id,
                        'payment_mode' => $columnMode,
                    ]);
                }

                $linkage->save();
                $dropped++;
            }
        });

        Log::info("[payment_mode cleanup] Removed the inline copy from {$dropped} configuration(s); corrected {$corrected} column(s) from it.");
    }

    /**
     * Deliberately does nothing.
     *
     * Nothing records which rows carried an inline copy, so a reversal could only
     * re-add one to every row - including the majority that were always clean.
     * That would not restore the previous state; it would put a second, stale
     * source of truth on rows that never had one, which is the exact defect this
     * migration exists to remove.
     *
     * The column is the source of truth either way, so leaving the data alone
     * costs nothing. The log lines written by up() are the audit trail if the
     * pre-migration values are ever needed.
     */
    public function down(): void
    {
        Log::info('[payment_mode cleanup] Reversal is a no-op; the column remains the source of truth.');
    }
};
