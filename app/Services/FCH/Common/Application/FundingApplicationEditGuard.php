<?php

namespace App\Services\FCH\Common\Application;

use App\Exceptions\FundingApplicationLockedException;
use App\Models\Borrower\FundingApplication;

/**
 * The one place that decides what a borrower or partner is allowed to do to an
 * application they have already submitted, and what it costs them.
 *
 * Three rules, applied in order:
 *
 *   1. If the status is locked (with a lender, under contract, funded, closed,
 *      denied) nobody outside admin may change the application at all.
 *   2. If the status carries a signature, a save that genuinely changes a field
 *      invalidates that signature: the application drops back to Draft and the
 *      borrower must sign again. A partner's edit costs the *borrower's*
 *      signature, which is the whole point - the borrower attests to the data,
 *      so changed data needs a fresh attestation.
 *   3. A save that changes nothing costs nothing. The wizard re-posts every
 *      step's prefilled values, so paging through an application without
 *      touching it must not force a resign.
 *
 * Both the borrower service and the partner service route through this, because
 * they used to carry near-identical copies of rule 2 that had already drifted
 * apart (different ignore lists, different handling of statuses past Pending).
 *
 * Which statuses fall in which bucket is config, not code - see
 * config/funding_application.php under `statuses`.
 */
class FundingApplicationEditGuard
{
    /**
     * Refuse the edit outright if the application is no longer theirs to change.
     *
     * @throws FundingApplicationLockedException
     */
    public function assertEditable(?FundingApplication $application): void
    {
        // A new application has no status yet; there is nothing to lock.
        if (! $application || ! $application->exists) {
            return;
        }

        if ($application->isLockedForEdit()) {
            throw FundingApplicationLockedException::for($application);
        }
    }

    /**
     * The fields this save would actually change, ignoring the bookkeeping the
     * server writes itself.
     *
     * @param  array<string, mixed>  $data
     * @return array<int, string>
     */
    public function changedFields(FundingApplication $existing, array $data): array
    {
        $candidates = array_values(array_diff(
            array_keys($data),
            FundingApplication::editComparisonIgnoredFields()
        ));

        if ($candidates === []) {
            return [];
        }

        // Untouched optional fields round-trip through the wizard as '' rather
        // than null, which a cast (e.g. integer) would otherwise turn into a
        // false "0 !== null" dirty flag. Normalize for the comparison only - the
        // real save still gets $data as-is.
        $comparable = array_map(static fn ($value) => $value === '' ? null : $value, $data);

        // Compare against a copy. $existing belongs to the caller and is read
        // again after this returns, so a dirty-check must not leak into the save.
        $probe = clone $existing;
        $probe->fill($comparable);

        return array_values(array_intersect($candidates, array_keys($probe->getDirty())));
    }

    /**
     * Apply the editing rules to a save that is about to happen.
     *
     * The caller decides `$data['status']` before calling - the two portals park
     * an in-progress application at different statuses, and that is theirs to
     * own. This only ever overrides the status in the one case it has a right
     * to: a real edit that invalidates a signature.
     *
     * @param  array<string, mixed>  $data            the payload being saved, with the
     *                                                status the caller intends
     * @param  string|null           $explicitStatus  a status the caller is deliberately
     *                                                moving to (submit / re-sign); when
     *                                                present the caller owns the status
     *                                                and the signature it just wrote,
     *                                                so no resign is forced
     * @return array{data: array<string, mixed>, changed_fields: array<int, string>, requires_resign: bool}
     *
     * @throws FundingApplicationLockedException
     */
    public function applyEditRules(FundingApplication $existing, array $data, ?string $explicitStatus = null): array
    {
        $this->assertEditable($existing);

        $changedFields = $this->changedFields($existing, $data);

        $leaveStatusToCaller =
            // Submitting or re-signing - this save *is* the signature.
            ! empty($explicitStatus)
            // Nothing signed yet (On Hold / Draft), so there is nothing to invalidate.
            || ! $existing->invalidatesSignatureOnEdit()
            // Signed, but this save moves nothing. The wizard re-posts every step's
            // prefilled values; paging through must not cost a signature.
            || $changedFields === [];

        if ($leaveStatusToCaller) {
            return $this->result($data, $changedFields, false);
        }

        // A real edit to a signed application. The edit is still persisted; what
        // it loses is the signature attesting to the old data.
        $data['status'] = FundingApplication::resignTargetStatus();
        $data['signature_data'] = null;
        $data['attestation_authorization'] = false;
        $data['requires_resign'] = true;

        return $this->result($data, $changedFields, true);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, string>  $changedFields
     * @return array{data: array<string, mixed>, changed_fields: array<int, string>, requires_resign: bool}
     */
    private function result(array $data, array $changedFields, bool $requiresResign): array
    {
        return [
            'data' => $data,
            'changed_fields' => $changedFields,
            'requires_resign' => $requiresResign,
        ];
    }
}
