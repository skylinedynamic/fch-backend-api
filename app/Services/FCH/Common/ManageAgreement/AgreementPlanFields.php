<?php

namespace App\Services\FCH\Common\ManageAgreement;

use App\Models\Common\AgreementTemplate;
use App\Models\Partner\SubscriptionPlan;

/**
 * Plan & Fee prices as agreement merge fields: the price list of the active
 * plans, the same for every signer (e.g. {{plan.borrower_enrollment.installment.total_amount}}).
 *
 * Keys are built from the plan's group and names, not its id, so a template
 * means the same plan on every server: plan.<group>[.<partner type>].<enrollment type>.<field>.
 * The partner agreement takes every group; the borrower agreement only
 * Borrower Enrollment - partner prices never reach a borrower.
 */
class AgreementPlanFields
{
    public const KEY_PREFIX = 'plan.';

    /** Plan groups each agreement may show, in the order the builder lists them. */
    private const GROUPS_BY_AGREEMENT = [
        AgreementTemplate::SLUG_PARTNER => [
            SubscriptionPlan::SUBSCRIPTION_PLAN_PARTNER_ENROLLMENT,
            SubscriptionPlan::SUBSCRIPTION_PLAN_BORROWER_ENROLLMENT,
            SubscriptionPlan::SUBSCRIPTION_PLAN_BORROWER_FEE,
            SubscriptionPlan::SUBSCRIPTION_PLAN_USER_SEAT,
        ],
        AgreementTemplate::SLUG_BORROWER => [
            SubscriptionPlan::SUBSCRIPTION_PLAN_BORROWER_ENROLLMENT,
        ],
    ];

    /** How each group is headed in the builder's menu. */
    private const GROUP_LABELS = [
        SubscriptionPlan::SUBSCRIPTION_PLAN_PARTNER_ENROLLMENT => 'Plan & Fee: Partner enrollment',
        SubscriptionPlan::SUBSCRIPTION_PLAN_USER_SEAT => 'Plan & Fee: User seats',
        SubscriptionPlan::SUBSCRIPTION_PLAN_BORROWER_FEE => 'Plan & Fee: Client activation fee',
        SubscriptionPlan::SUBSCRIPTION_PLAN_BORROWER_ENROLLMENT => 'Plan & Fee: Borrower enrollment',
    ];

    /** The agreement types that have Plan & Fee fields. */
    public static function agreementTypes(): array
    {
        return array_keys(self::GROUPS_BY_AGREEMENT);
    }

    /**
     * The fields an agreement of this type offers, as the builder lists them.
     *
     * @return list<array{key: string, label: string, group: string, sample: string}>
     */
    public function fields(string $agreementType): array
    {
        $fields = [];
        foreach ($this->plans($agreementType) as $plan) {
            foreach ($this->planFields($plan) as $field) {
                $fields[] = $field;
            }
        }

        return $fields;
    }

    /**
     * What signing fills each field with, for this type of agreement.
     *
     * @return array<string, string>
     */
    public function values(string $agreementType): array
    {
        return collect($this->fields($agreementType))->mapWithKeys(fn (array $f) => [$f['key'] => $f['sample']])->all();
    }

    public static function isPlanKey(string $key): bool
    {
        return str_starts_with($key, self::KEY_PREFIX);
    }

    /** @return \Illuminate\Support\Collection<int, SubscriptionPlan> */
    private function plans(string $agreementType)
    {
        $groups = self::GROUPS_BY_AGREEMENT[$agreementType] ?? [];
        if ($groups === []) {
            return collect();
        }

        return SubscriptionPlan::query()
            ->whereIn('group', $groups)
            ->where('status', 1)
            ->get()
            ->sortBy(fn (SubscriptionPlan $plan) => [array_search($plan->group, $groups, true), $plan->id])
            ->values();
    }

    /** @return list<array{key: string, label: string, group: string, sample: string}> */
    private function planFields(SubscriptionPlan $plan): array
    {
        $base = $this->baseKey($plan);
        $name = $this->planName($plan);
        $group = self::GROUP_LABELS[$plan->group];
        $installments = (int) $plan->total_installment_count_upto;

        $make = fn (string $field, string $label, string $value) => [
            'key' => "{$base}.{$field}", 'label' => $label, 'group' => $group, 'sample' => $value,
        ];

        return match ($plan->group) {
            SubscriptionPlan::SUBSCRIPTION_PLAN_PARTNER_ENROLLMENT => array_values(array_filter([
                $make('total_amount', "{$name}: Total amount", $this->money($plan, $plan->total_amount)),
                $plan->platform_fee !== null ? $make('platform_fee', "{$name}: Platform fee", $this->money($plan, $plan->platform_fee)) : null,
                $installments > 1 ? $make('installments', "{$name}: Number of installments", (string) $installments) : null,
            ])),
            SubscriptionPlan::SUBSCRIPTION_PLAN_USER_SEAT => array_values(array_filter([
                $make('total_amount', "{$name}: Monthly seat fee", $this->money($plan, $plan->total_amount)),
                $plan->total_seat_count !== null ? $make('free_seats', "{$name}: Free seats", (string) (int) $plan->total_seat_count) : null,
            ])),
            SubscriptionPlan::SUBSCRIPTION_PLAN_BORROWER_FEE => [
                $make('total_amount', 'Client activation fee', $this->money($plan, $plan->total_amount)),
            ],
            default => array_values(array_filter([
                $make('total_amount', "{$name}: Total amount", $this->money($plan, $plan->total_amount)),
                $installments > 1 ? $make('installments', "{$name}: Number of installments", (string) $installments) : null,
            ])),
        };
    }

    private function baseKey(SubscriptionPlan $plan): string
    {
        $parts = array_filter([$plan->group, $plan->partner_type_slug, $plan->enrollment_type_slug ?: $plan->enrollment_type]);

        return self::KEY_PREFIX . implode('.', array_map(fn ($part) => $this->keyPart((string) $part), $parts));
    }

    /** "white-label-partner-wl" -> "white_label_partner_wl": the characters a merge field key may hold. */
    private function keyPart(string $part): string
    {
        return trim(preg_replace('/[^a-z0-9]+/', '_', strtolower($part)), '_');
    }

    private function planName(SubscriptionPlan $plan): string
    {
        return implode(' · ', array_filter([$plan->partner_type, $plan->enrollment_type]));
    }

    private function money(SubscriptionPlan $plan, $amount): string
    {
        return ($plan->currency_symbol ?: '$') . number_format((float) $amount, 2);
    }
}
