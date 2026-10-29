<?php

namespace Tests\Unit\Services;

use App\Exceptions\FundingApplicationLockedException;
use App\Models\Borrower\FundingApplication;
use App\Services\FCH\Common\Application\FundingApplicationEditGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The rules themselves, with no HTTP, no auth and no database in the way.
 *
 * The guard is the single thing standing between a borrower or partner and an
 * application they should no longer be able to change, so its decisions are
 * worth pinning down directly rather than only through a controller.
 */
class FundingApplicationEditGuardTest extends TestCase
{
    private FundingApplicationEditGuard $guard;

    protected function setUp(): void
    {
        parent::setUp();

        $this->guard = new FundingApplicationEditGuard();
    }

    /**
     * A saved application in a given status, without touching the database.
     */
    private function application(string $status, array $attributes = []): FundingApplication
    {
        $application = new FundingApplication();
        $application->setRawAttributes(array_merge([
            'id' => 1,
            'status' => $status,
            'business_name' => 'Alpha Tech Solutions',
            'business_city' => 'Vancouver',
            'number_of_employees' => null,
            'requires_resign' => false,
        ], $attributes), true);
        $application->exists = true;

        return $application;
    }

    // ── The lock ─────────────────────────────────────────────────────

    /**
     * Spelled out from the constants rather than read back from config, because
     * a data provider runs before the application boots and config() is not
     * available yet. every_status_falls_in_exactly_one_editing_bucket() is what
     * keeps this list and the config in step.
     */
    public static function lockedStatusProvider(): array
    {
        return array_map(
            static fn (string $status) => [$status],
            [
                // Everything outside the `editable` list (SCRUM-331), the new closing statuses included.
                FundingApplication::MATCHED_TO_LENDER,
                FundingApplication::SUBMITTED_TO_LENDER,
                FundingApplication::LENDER_REVIEWING,
                FundingApplication::OFFER_ISSUED,
                FundingApplication::OFFER_ACCEPTED,
                FundingApplication::CONTRACTING_DOCS_OUT,
                FundingApplication::CLEARED_TO_FUND,
                FundingApplication::FUNDED,
                FundingApplication::CLOSED_COMPLETED,
                FundingApplication::DENIED,
                FundingApplication::WITHDRAWN,
                FundingApplication::OFFER_DECLINED_BY_BORROWER,
                FundingApplication::CLOSED_NO_RESPONSE,
                FundingApplication::CLOSED_INCOMPLETE_APPLICATION,
                FundingApplication::CLOSED_ERROR,
            ]
        );
    }

    #[Test]
    public function the_locked_status_list_in_config_matches_the_one_these_tests_drive(): void
    {
        $fromProvider = array_map(static fn (array $row) => $row[0], self::lockedStatusProvider());

        $this->assertSame(FundingApplication::lockedStatuses(), $fromProvider);
    }

    #[Test]
    #[DataProvider('lockedStatusProvider')]
    public function every_locked_status_refuses_an_edit(string $status): void
    {
        $this->expectException(FundingApplicationLockedException::class);

        $this->guard->applyEditRules(
            $this->application($status),
            ['business_name' => 'Something Else']
        );
    }

    #[Test]
    public function the_refusal_carries_the_status_that_caused_it(): void
    {
        try {
            $this->guard->assertEditable($this->application(FundingApplication::FUNDED));
            $this->fail('A funded application must not be editable.');
        } catch (FundingApplicationLockedException $e) {
            $this->assertSame(FundingApplication::FUNDED, $e->applicationStatus);
            $this->assertSame(423, $e->getStatusCode());
            $this->assertStringContainsString(FundingApplication::FUNDED, $e->getMessage());
        }
    }

    #[Test]
    public function an_application_that_does_not_exist_yet_is_not_locked(): void
    {
        $unsaved = new FundingApplication(['status' => FundingApplication::FUNDED]);

        // No exception: there is no application to lock.
        $this->guard->assertEditable($unsaved);
        $this->guard->assertEditable(null);

        $this->assertTrue(true);
    }

    #[Test]
    public function an_unsigned_application_is_editable(): void
    {
        foreach (FundingApplication::preSignatureStatuses() as $status) {
            $result = $this->guard->applyEditRules(
                $this->application($status),
                ['status' => $status, 'business_name' => 'Renamed Co']
            );

            $this->assertFalse($result['requires_resign'], "{$status} must not force a resign");
            // The caller's status stands - the guard only overrides to force a resign.
            $this->assertSame($status, $result['data']['status']);
        }
    }

    // ── Which fields count as a change ───────────────────────────────

    #[Test]
    public function an_empty_string_for_a_stored_null_is_not_a_change(): void
    {
        // The wizard round-trips untouched optional fields as '' rather than null.
        $changed = $this->guard->changedFields(
            $this->application(FundingApplication::PENDING),
            ['business_name' => 'Alpha Tech Solutions', 'number_of_employees' => '']
        );

        $this->assertSame([], $changed);
    }

    #[Test]
    public function changed_fields_names_exactly_what_moved(): void
    {
        $changed = $this->guard->changedFields(
            $this->application(FundingApplication::PENDING),
            [
                'business_name' => 'Alpha Tech Solutions LLC', // changed
                'business_city' => 'Vancouver',                // unchanged
            ]
        );

        $this->assertSame(['business_name'], $changed);
    }

    #[Test]
    public function bookkeeping_the_server_writes_itself_is_not_a_change(): void
    {
        $changed = $this->guard->changedFields(
            $this->application(FundingApplication::PENDING),
            [
                'status' => FundingApplication::DRAFT,
                'updated_by' => 99,
                'requires_resign' => true,
                'signature_data' => null,
                'is_business_info_saved' => true,
                'business_name' => 'Alpha Tech Solutions', // unchanged
            ]
        );

        $this->assertSame([], $changed, 'only borrower-entered data may cost a signature');
    }

    #[Test]
    public function the_dirty_check_does_not_mutate_the_application_it_inspects(): void
    {
        $application = $this->application(FundingApplication::PENDING);

        $this->guard->changedFields($application, ['business_name' => 'Mutated Co']);

        $this->assertSame(
            'Alpha Tech Solutions',
            $application->business_name,
            'the comparison must run on a copy - the caller saves this instance afterwards'
        );
    }

    // ── What an edit costs ───────────────────────────────────────────

    #[Test]
    public function a_real_change_to_a_signed_application_clears_the_signature(): void
    {
        $result = $this->guard->applyEditRules(
            $this->application(FundingApplication::UNDERWRITING_REVIEW, [
                'signature_data' => json_encode(['signature_name' => 'Michael Chen']),
                'attestation_authorization' => true,
            ]),
            ['status' => FundingApplication::UNDERWRITING_REVIEW, 'business_name' => 'Alpha Tech Solutions LLC']
        );

        $this->assertTrue($result['requires_resign']);
        $this->assertSame(['business_name'], $result['changed_fields']);
        $this->assertSame(FundingApplication::resignTargetStatus(), $result['data']['status']);
        $this->assertNull($result['data']['signature_data']);
        $this->assertFalse($result['data']['attestation_authorization']);
        $this->assertTrue($result['data']['requires_resign']);
        $this->assertSame(
            'Alpha Tech Solutions LLC',
            $result['data']['business_name'],
            'the edit itself is still saved - only the signature is withdrawn'
        );
    }

    #[Test]
    public function a_save_that_changes_nothing_costs_nothing(): void
    {
        $result = $this->guard->applyEditRules(
            $this->application(FundingApplication::PENDING),
            ['status' => FundingApplication::PENDING, 'business_name' => 'Alpha Tech Solutions']
        );

        $this->assertFalse($result['requires_resign']);
        $this->assertSame([], $result['changed_fields']);
        $this->assertSame(FundingApplication::PENDING, $result['data']['status']);
        $this->assertArrayNotHasKey('signature_data', $result['data']);
    }

    #[Test]
    public function an_explicit_status_is_the_caller_signing_not_an_edit(): void
    {
        // saveAttestationAndAuthorization passes the status it is moving to; that
        // save *is* the signature, so it must not invalidate itself.
        $result = $this->guard->applyEditRules(
            $this->application(FundingApplication::DRAFT, ['requires_resign' => true]),
            ['status' => FundingApplication::PENDING, 'business_name' => 'Alpha Tech Solutions LLC'],
            FundingApplication::PENDING
        );

        $this->assertFalse($result['requires_resign']);
        $this->assertSame(FundingApplication::PENDING, $result['data']['status']);
    }

    // ── The buckets themselves ───────────────────────────────────────

    #[Test]
    public function every_status_falls_in_exactly_one_editing_bucket(): void
    {
        $all = array_values(FundingApplication::STATUS);
        $bucketed = array_merge(
            FundingApplication::preSignatureStatuses(),
            FundingApplication::resignOnEditStatuses(),
            FundingApplication::lockedStatuses()
        );

        $this->assertSame([], array_values(array_diff($all, $bucketed)), 'every status needs a bucket');
        $this->assertSame([], array_values(array_diff($bucketed, $all)), 'no bucket may name a status that does not exist');
        $this->assertCount(count($all), $bucketed, 'no status may sit in two buckets');
    }

    #[Test]
    public function the_editable_list_is_exactly_the_two_editable_buckets(): void
    {
        // `editable` is what enforcement reads, and the two buckets below it are
        // what decide the cost. If they ever disagree, one of the three lists has
        // been edited without the others and something is quietly wrong.
        $this->assertEqualsCanonicalizing(
            array_merge(
                FundingApplication::preSignatureStatuses(),
                FundingApplication::resignOnEditStatuses()
            ),
            FundingApplication::editableStatuses()
        );
    }

    #[Test]
    public function editable_and_locked_split_the_statuses_between_them(): void
    {
        $editable = FundingApplication::editableStatuses();
        $locked = FundingApplication::lockedStatuses();

        $this->assertSame([], array_values(array_intersect($editable, $locked)), 'no status may be both');
        $this->assertEqualsCanonicalizing(
            array_values(FundingApplication::STATUS),
            array_merge($editable, $locked),
            'together they must account for every status'
        );
    }

    #[Test]
    public function every_editable_status_really_is_editable(): void
    {
        foreach (FundingApplication::editableStatuses() as $status) {
            $application = $this->application($status);

            $this->assertTrue($application->isEditable(), "{$status} must be editable");
            $this->assertFalse($application->isLockedForEdit(), "{$status} must not be locked");

            // And the guard agrees - no exception thrown.
            $this->guard->assertEditable($application);
        }
    }

    /**
     * SCRUM-331: editability is an explicit list, not "order key 9 or below".
     * Stage's nine editable statuses stay editable, and of the seven new ones
     * only Pending Clarification and Ready for Submission join them.
     */
    #[Test]
    public function exactly_the_eleven_listed_statuses_are_editable(): void
    {
        $editable = [
            FundingApplication::ON_HOLD,
            FundingApplication::DRAFT,
            FundingApplication::PENDING,
            FundingApplication::SUBMITTED,
            FundingApplication::UNDERWRITING_REVIEW,
            FundingApplication::MISSING_DOCUMENTS,
            FundingApplication::IN_VERIFICATION,
            FundingApplication::PENDING_CLARIFICATION,
            FundingApplication::READY_FOR_SUBMISSION,
            FundingApplication::APPROVED_CONDITIONAL,
            FundingApplication::APPROVED_FINAL,
        ];

        $this->assertSame($editable, FundingApplication::editableStatuses());

        foreach (FundingApplication::STATUS as $status) {
            $this->assertSame(
                in_array($status, $editable, true),
                $this->application($status)->isEditable(),
                $status
            );
        }
    }

    /**
     * SCRUM-331: stage's order, with Pending Clarification and Ready for
     * Submission right after In Verification and the five new closing
     * statuses after Denied.
     */
    #[Test]
    public function the_new_statuses_sit_in_the_agreed_order(): void
    {
        $this->assertSame([
            FundingApplication::ON_HOLD,
            FundingApplication::DRAFT,
            FundingApplication::PENDING,
            FundingApplication::SUBMITTED,
            FundingApplication::UNDERWRITING_REVIEW,
            FundingApplication::MISSING_DOCUMENTS,
            FundingApplication::IN_VERIFICATION,
            // SCRUM-331: the two new editable statuses come right after In Verification.
            FundingApplication::PENDING_CLARIFICATION,
            FundingApplication::READY_FOR_SUBMISSION,
            FundingApplication::APPROVED_CONDITIONAL,
            FundingApplication::APPROVED_FINAL,
            FundingApplication::MATCHED_TO_LENDER,
            FundingApplication::SUBMITTED_TO_LENDER,
            FundingApplication::LENDER_REVIEWING,
            FundingApplication::OFFER_ISSUED,
            FundingApplication::OFFER_ACCEPTED,
            FundingApplication::CONTRACTING_DOCS_OUT,
            FundingApplication::CLEARED_TO_FUND,
            FundingApplication::FUNDED,
            FundingApplication::CLOSED_COMPLETED,
            FundingApplication::DENIED,
            FundingApplication::WITHDRAWN,
            FundingApplication::OFFER_DECLINED_BY_BORROWER,
            FundingApplication::CLOSED_NO_RESPONSE,
            FundingApplication::CLOSED_INCOMPLETE_APPLICATION,
            FundingApplication::CLOSED_ERROR,
        ], array_keys(FundingApplication::statusKeys()));
        $this->assertSame(range(1, 26), array_values(FundingApplication::statusKeys()));
        // The dropdown and validation read the model's list, in the same order.
        $this->assertSame(array_keys(FundingApplication::statusKeys()), array_values(FundingApplication::STATUS));
        $this->assertSame(array_keys(FundingApplication::STATUS), array_keys(FundingApplication::STATUS_CLASS));
        $this->assertSame('Closed - No Response', FundingApplication::CLOSED_NO_RESPONSE);
    }

    // ── Statuses written by older versions ───────────────────────────

    #[Test]
    public function a_legacy_status_is_read_as_its_modern_equivalent(): void
    {
        // Rows predating 'Submitted - Pending Review' still carry a bare
        // 'Pending'. It means submitted, so it must stay editable and an edit
        // must still cost the signature.
        $legacy = $this->application('Pending');

        $this->assertTrue($legacy->isEditable(), 'an old submitted application must not become read-only');
        $this->assertTrue($legacy->invalidatesSignatureOnEdit(), 'it is signed, so an edit still costs the signature');
        $this->assertFalse($legacy->allowsNewFundingRequest());
    }

    #[Test]
    public function an_unclassified_status_is_treated_as_read_only(): void
    {
        // Failing closed: a status nobody has put in a bucket is more likely to
        // be one added past the point of editing than one that should stay open.
        $unknown = $this->application('Sent To Syndication');

        $this->assertFalse($unknown->isEditable());
        $this->assertTrue($unknown->isLockedForEdit());

        $this->expectException(FundingApplicationLockedException::class);
        $this->guard->assertEditable($unknown);
    }

    #[Test]
    public function re_applying_is_only_offered_on_applications_that_are_read_only(): void
    {
        $this->assertSame(
            [],
            array_values(array_diff(
                FundingApplication::statusesAllowingNewRequest(),
                FundingApplication::lockedStatuses()
            )),
            'an application the borrower may re-apply from must itself be out of their hands'
        );
    }

    #[Test]
    public function every_status_out_of_the_borrowers_hands_lets_them_apply_again(): void
    {
        // The whole locked set, Funded included: that round is settled either
        // way, and a settled round is not a reason to block a fresh request.
        $this->assertSame(
            FundingApplication::lockedStatuses(),
            FundingApplication::statusesAllowingNewRequest()
        );
    }

    #[Test]
    public function an_application_still_in_progress_does_not_let_them_apply_again(): void
    {
        foreach (array_merge(FundingApplication::preSignatureStatuses(), FundingApplication::resignOnEditStatuses()) as $status) {
            $this->assertFalse(
                $this->application($status)->allowsNewFundingRequest(),
                "{$status} is still in progress - a second request would run a parallel pipeline"
            );
        }
    }
}
