<?php

namespace App\Services\FCH\Common\ManageAgreement;

use App\Models\Borrower\Borrower;
use App\Models\Common\AgreementTemplate;
use App\Models\Partner\Partner;
use App\Models\Partner\SubscriptionPlan;
use App\Models\Subscription\Subscription;
use App\Models\User\User;

/**
 * {{signer.enrollment_table}}: a table of the amounts set for the signer when
 * they enrolled (their enrollment subscription) - a partner's own in the
 * partner agreement, a borrower's own in the borrower agreement. A template
 * can't know whether a signer pays once or in installments, so the table takes
 * the rows of the signer's enrollment type (VARIANTS); the builder shows the
 * same list so the host knows what each signer will see.
 *
 * Like the commission table (AgreementCommissionFields), it is markup built
 * here, never typed text, and its token alone in a paragraph takes that
 * paragraph's place (AgreementMergeFields::unwrapTable()).
 */
class AgreementEnrollmentFields
{
    public const KEY = 'signer.enrollment_table';

    /** The fields of the earlier one-amount-per-field version: left blank if a template still has one. */
    public const LEGACY_PREFIX = 'signer.enrollment.';

    private const TYPE = 'type';
    private const TOTAL = 'total_amount';
    private const DOWN_PAYMENT = 'down_payment';
    private const SUBSCRIPTION = 'subscription_amount';
    private const MONTHLY = 'monthly_subscription';
    private const INSTALLMENT_MONTHS = 'installment_months';
    private const PLATFORM_FEE = 'platform_fee';
    private const SEAT_FEE = 'per_user_seat_fee';
    private const FREE_SEATS = 'free_seat_count';
    private const ACTIVATION_FEE = 'client_activation_fee';

    private const INSTALLMENT = SubscriptionPlan::ENROLLMENT_TYPE_INSTALLMENT;
    private const ONE_TIME = SubscriptionPlan::ENROLLMENT_TYPE_ONE_TIME_PAYMENT;

    /** The rows each enrollment type shows, per agreement. */
    private const VARIANTS = [
        AgreementTemplate::SLUG_PARTNER => [
            self::INSTALLMENT => [
                self::TYPE, self::TOTAL, self::DOWN_PAYMENT, self::SUBSCRIPTION, self::MONTHLY, self::INSTALLMENT_MONTHS,
                self::PLATFORM_FEE, self::SEAT_FEE, self::FREE_SEATS, self::ACTIVATION_FEE,
            ],
            self::ONE_TIME => [self::TYPE, self::TOTAL, self::PLATFORM_FEE, self::SEAT_FEE, self::FREE_SEATS, self::ACTIVATION_FEE],
        ],
        AgreementTemplate::SLUG_BORROWER => [
            self::INSTALLMENT => [self::TYPE, self::TOTAL, self::DOWN_PAYMENT, self::SUBSCRIPTION, self::MONTHLY, self::INSTALLMENT_MONTHS],
            self::ONE_TIME => [self::TYPE, self::TOTAL],
        ],
    ];

    /** Rows that are a count, not an amount of money. */
    private const COUNTS = [self::INSTALLMENT_MONTHS, self::FREE_SEATS];

    /** The builder's examples, one per enrollment type (the preview shows both). */
    private const SAMPLES = [
        self::INSTALLMENT => [
            self::TOTAL => 6000, self::DOWN_PAYMENT => 500, self::SUBSCRIPTION => 5500, self::MONTHLY => 229.17,
            self::INSTALLMENT_MONTHS => 24, self::PLATFORM_FEE => 299, self::SEAT_FEE => 49, self::FREE_SEATS => 3,
        ],
        self::ONE_TIME => [
            self::TOTAL => 4997, self::PLATFORM_FEE => 199, self::SEAT_FEE => 49, self::FREE_SEATS => 3,
        ],
    ];

    private const CAPTION_STYLE = 'margin:12px 0 4px;font-weight:600';

    private const TABLE_STYLE = 'width:100%;border-collapse:collapse;margin:8px 0;font-size:inherit';

    private const CELL_STYLE = 'border:1px solid #d0d5dd;padding:6px 10px;text-align:left';

    private const HEAD_STYLE = self::CELL_STYLE . ';background:#f5f6f8;font-weight:600';

    public static function isKey(string $key): bool
    {
        return $key === self::KEY;
    }

    public static function isLegacyKey(string $key): bool
    {
        return str_starts_with($key, self::LEGACY_PREFIX);
    }

    /**
     * The field this agreement offers, with an example table and the rows each
     * enrollment type shows (the builder explains them).
     *
     * @return list<array{key: string, label: string, group: string, sample: string, html: bool, variants: list<array{type: string, rows: list<string>}>}>
     */
    public function fields(string $agreementType): array
    {
        $variants = self::VARIANTS[$agreementType] ?? null;
        if (!$variants) {
            return [];
        }

        return [[
            'key' => self::KEY,
            'label' => __('agreement.enrollment.field_label'),
            'group' => __('agreement.enrollment.group'),
            'sample' => $this->samples($variants),
            'html' => true,
            'variants' => array_map(fn (string $type) => [
                'type' => $type,
                'rows' => array_map(fn (string $row) => $this->label($row), $variants[$type]),
            ], array_keys($variants)),
        ]];
    }

    /**
     * What signing fills the table with: the signer's enrollment, in the rows
     * of its type; a line saying there is none when the signer has no enrollment.
     *
     * @return array<string, string>
     */
    public function values(User $signer, ?string $agreementType): array
    {
        $variants = self::VARIANTS[$agreementType] ?? null;
        if (!$variants) {
            return [];
        }

        $subscription = $this->enrollment($signer, $agreementType);
        if (!$subscription) {
            return [self::KEY => e(__('agreement.enrollment.none'))];
        }

        $installment = (int) $subscription->installment_type === 1;
        $type = $installment ? self::INSTALLMENT : self::ONE_TIME;
        $symbol = $subscription->currency_symbol ?: '$';
        $amount = fn ($value) => $value === null ? '' : $this->money($symbol, $value);

        $values = [
            self::TYPE => $type,
            self::TOTAL => $amount($subscription->payable_amount),
            self::DOWN_PAYMENT => $amount($subscription->down_payment),
            self::SUBSCRIPTION => $amount($subscription->total_subscription_amount),
            self::MONTHLY => $amount($subscription->subscription_amount_per_month),
            self::INSTALLMENT_MONTHS => $subscription->installment_count === null ? '' : (string) (int) $subscription->installment_count,
            self::PLATFORM_FEE => $amount($subscription->monthly_platform_fee),
            self::ACTIVATION_FEE => $this->activationFee(),
        ];

        // A partner's seats: its own on the enrollment, else its type's Plan &
        // Fee seat plan (the rule Partner::userSeatLimit() uses for the count).
        if ($agreementType === AgreementTemplate::SLUG_PARTNER) {
            $seatPlan = $signer->partner?->userSeatPlan();
            $seatFee = $subscription->per_seat_amount ?? $seatPlan?->total_amount;
            $freeSeats = $subscription->user_seats ?? $seatPlan?->total_seat_count;
            $values[self::SEAT_FEE] = $seatFee === null ? '' : $this->money($seatPlan?->currency_symbol ?: $symbol, $seatFee);
            $values[self::FREE_SEATS] = $freeSeats === null ? '' : (string) (int) $freeSeats;
        }

        return [self::KEY => $this->table($variants[$type], $values)];
    }

    /**
     * The preview's example: a captioned table for each enrollment type, as a
     * signer of that type would see it (a signer sees only their own).
     *
     * @param array<string, list<string>> $variants
     */
    private function samples(array $variants): string
    {
        $activationFee = $this->activationFee();

        return implode('', array_map(function (string $type) use ($variants, $activationFee) {
            $values = [];
            foreach (self::SAMPLES[$type] as $row => $sample) {
                $values[$row] = in_array($row, self::COUNTS, true) ? (string) $sample : $this->money('$', $sample);
            }
            $values[self::TYPE] = $type;
            $values[self::ACTIVATION_FEE] = $activationFee;

            return '<p class="ab-enrollment-example" style="' . self::CAPTION_STYLE . '">'
                . e(__('agreement.enrollment.example', ['type' => $type])) . '</p>'
                . $this->table($variants[$type], $values);
        }, array_keys($variants)));
    }

    /** @param list<string> $rows @param array<string, string> $values */
    private function table(array $rows, array $values): string
    {
        $cell = fn (string $text, string $tag = 'td') => '<' . $tag . ' style="' . ($tag === 'th' ? self::HEAD_STYLE : self::CELL_STYLE) . '">'
            . e($text) . '</' . $tag . '>';

        $body = implode('', array_map(fn (string $row) => '<tr>' . $cell($this->label($row)) . $cell($values[$row] ?? '') . '</tr>', $rows));

        return '<table class="ab-enrollment-table" style="' . self::TABLE_STYLE . '"><thead><tr>'
            . $cell(__('agreement.enrollment.item'), 'th')
            . $cell(__('agreement.enrollment.amount'), 'th')
            . '</tr></thead><tbody>' . $body . '</tbody></table>';
    }

    private function label(string $row): string
    {
        return __('agreement.enrollment.rows.' . $row);
    }

    /**
     * The signer's enrollment subscription: a partner's in the partner
     * agreement, a borrower's in the borrower agreement - the latest one, never
     * a user seat.
     */
    private function enrollment(User $signer, string $agreementType): ?Subscription
    {
        $entityType = $agreementType === AgreementTemplate::SLUG_PARTNER ? Partner::class : Borrower::class;
        if ($signer->entity_type !== $entityType || !$signer->entity_id) {
            return null;
        }

        return Subscription::where('entity_type', $entityType)
            ->where('entity_id', $signer->entity_id)
            ->where(fn ($query) => $query->whereNull('current_phase')->orWhere('current_phase', '!=', Subscription::PHASE_USER_SEAT))
            ->where(fn ($query) => $query->whereNull('enrollment_type')->orWhere('enrollment_type', '!=', Subscription::ENROLLMENT_TYPE_MONTHLY))
            ->latest('id')
            ->first();
    }

    /** Plan & Fee's client activation fee, which a partner may set per borrower. */
    private function activationFee(): string
    {
        $plan = SubscriptionPlan::where('group', SubscriptionPlan::SUBSCRIPTION_PLAN_BORROWER_FEE)
            ->where('enrollment_type_slug', SubscriptionPlan::ENROLLMENT_TYPE_BORROWER_FIXED_ENROLLMENT_SLUG)
            ->where('status', 1)
            ->latest('id')
            ->first();

        return $plan
            ? __('agreement.enrollment.may_vary', ['amount' => $this->money($plan->currency_symbol ?: '$', $plan->total_amount)])
            : '';
    }

    private function money(string $symbol, $amount): string
    {
        return $symbol . number_format((float) $amount, 2);
    }
}
