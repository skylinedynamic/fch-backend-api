<?php

namespace App\Services\FCH\Common\ManageAgreement;

use App\Models\Common\AgreementTemplate;
use App\Models\Partner\Partner;
use App\Models\User\User;
use App\Services\FCH\Partner\CommissionTierFormatter;

/**
 * {{signer.commission_table}}: the signing partner's commission tiers as a
 * table (From / To / Commission), offered only in the partner agreement.
 *
 * Unlike the other merge fields its value is markup, so AgreementMergeFields
 * writes it unescaped. It is built here from numbers and lang strings only,
 * never from text anyone typed.
 */
class AgreementCommissionFields
{
    public const KEY = 'signer.commission_table';

    private const TABLE_STYLE = 'width:100%;border-collapse:collapse;margin:8px 0;font-size:inherit';

    private const CELL_STYLE = 'border:1px solid #d0d5dd;padding:6px 10px;text-align:left';

    private const HEAD_STYLE = self::CELL_STYLE . ';background:#f5f6f8;font-weight:600';

    /** Example tiers for the builder's preview. */
    private const SAMPLE_TIERS = [
        ['min_count' => 0, 'max_count' => 10, 'commission_type' => 'flat', 'amount' => 1500, 'percentage' => null],
        ['min_count' => 11, 'max_count' => null, 'commission_type' => 'percentage', 'amount' => null, 'percentage' => 27.5],
    ];

    public static function isKey(string $key): bool
    {
        return $key === self::KEY;
    }

    /**
     * The field as the builder lists it - only for the partner agreement.
     *
     * @return list<array{key: string, label: string, group: string, sample: string, html: bool}>
     */
    public function fields(string $agreementType): array
    {
        if ($agreementType !== AgreementTemplate::SLUG_PARTNER) {
            return [];
        }

        return [[
            'key' => self::KEY,
            'label' => __('agreement.commission.field_label'),
            'group' => 'Signer',
            'sample' => $this->table(self::SAMPLE_TIERS),
            'html' => true,
        ]];
    }

    /**
     * What signing fills the field with: the partner's own tiers. Nothing for
     * any other agreement or signer.
     *
     * @return array<string, string>
     */
    public function values(User $signer, ?string $agreementType): array
    {
        if ($agreementType !== AgreementTemplate::SLUG_PARTNER || $signer->entity_type !== Partner::class || !$signer->partner) {
            return [];
        }

        $tiers = $signer->partner->commissionTiers()->orderBy('sort_order')->orderBy('min_count')->get()
            ->map(fn ($tier) => [
                'min_count' => (int) $tier->min_count,
                'max_count' => $tier->max_count === null ? null : (int) $tier->max_count,
                'commission_type' => $tier->commission_type,
                'percentage' => $tier->percentage,
                'amount' => $tier->amount,
            ])->all();

        return [self::KEY => $tiers === [] ? e(__('agreement.commission.none')) : $this->table($tiers)];
    }

    /** @param array<int, array{min_count:int, max_count:?int, commission_type:?string, percentage:mixed, amount:mixed}> $tiers */
    private function table(array $tiers): string
    {
        $cell = fn (string $text, string $tag = 'td') => '<' . $tag . ' style="' . ($tag === 'th' ? self::HEAD_STYLE : self::CELL_STYLE) . '">'
            . e($text) . '</' . $tag . '>';

        $rows = array_map(fn (array $tier) => '<tr>'
            . $cell((string) $tier['min_count'])
            . $cell(CommissionTierFormatter::maxLabel($tier['max_count']))
            . $cell(CommissionTierFormatter::value($tier))
            . '</tr>', $tiers);

        return '<table class="ab-commission-table" style="' . self::TABLE_STYLE . '"><thead><tr>'
            . $cell(__('agreement.commission.from'), 'th')
            . $cell(__('agreement.commission.to'), 'th')
            . $cell(__('agreement.commission.commission'), 'th')
            . '</tr></thead><tbody>' . implode('', $rows) . '</tbody></table>';
    }
}
