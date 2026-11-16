<?php

namespace App\Services\FCH\Common\ManageAgreement;

use App\Models\Partner\Partner;
use App\Models\User\User;

/**
 * Merge fields in agreement text - {{signer.name}}, {{owner.company_name}},
 * {{today}} ... - inserted from the Agreement Builder's "Insert field" menu
 * and filled with the signer's details when the agreement is shown to them
 * and when the signed record is built.
 *
 * "signer" is whoever signs: a borrower, or a partner signing the host's
 * partner agreement. "owner" is the partner whose agreement it is. Only the
 * fields in KEYS are ever filled - nothing sensitive (SSN, EIN, bank or
 * payment details) is offered - and every value is written as text.
 *
 * Plan & Fee prices ({{plan....}}, AgreementPlanFields) are filled too, for the
 * agreement type: all of them in the partner agreement, only Borrower
 * Enrollment in the borrower agreement. A plan field with no value there (a
 * partner price in a borrower agreement, a plan switched off) is left blank.
 */
class AgreementMergeFields
{
    /** Offered by the Agreement Builder (Fch-admin-frontend-react src/config/agreementMergeFields.ts). */
    public const KEYS = [
        'signer.name',
        'signer.email',
        'signer.phone',
        'signer.address',
        'signer.business_name',
        'signer.business_address',
        'owner.company_name',
        'owner.email',
        'owner.phone',
        'owner.address',
        'today',
    ];

    /** @return array<string, string> */
    public function values(User $signer, ?Partner $owner, ?string $agreementType = null): array
    {
        $values = array_intersect_key(array_merge($this->signer($signer), $this->ownerValues($owner)), array_flip(self::KEYS));

        if (!$agreementType) {
            return $values;
        }

        return array_merge(
            $values,
            (new AgreementPlanFields())->values($agreementType),
            (new AgreementCommissionFields())->values($signer, $agreementType)
        );
    }

    /**
     * The template owner's details and today's date - all a template has before
     * anyone signs it (the builder's preview shows them).
     *
     * @return array<string, string>
     */
    public function ownerValues(?Partner $owner): array
    {
        return [
            'owner.company_name' => (string) ($owner?->company_name ?? ''),
            'owner.email' => (string) ($owner?->email ?? ''),
            'owner.phone' => (string) ($owner?->phone_number ?? ''),
            'owner.address' => $owner ? $this->partnerAddress($owner) : '',
            'today' => now()->format('m/d/Y'),
        ];
    }

    /** @param array<string, string> $values */
    public function fill(?string $html, array $values): ?string
    {
        if ($html === null || !str_contains($html, '{{')) {
            return $html;
        }

        $html = $this->unwrapTable($html, $values);

        return preg_replace_callback(
            '/\{\{\s*([a-z0-9_.]+)\s*\}\}/',
            fn (array $match) => match (true) {
                // Markup built by the server (AgreementCommissionFields), never typed text.
                AgreementCommissionFields::isKey($match[1]) => $values[$match[1]] ?? '',
                array_key_exists($match[1], $values) => htmlspecialchars($values[$match[1]], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                // Never the raw {{plan....}}: not for this agreement, or the plan is off.
                AgreementPlanFields::isPlanKey($match[1]) => '',
                default => $match[0],
            },
            $html
        );
    }

    /**
     * A commission table whose token sits alone in its paragraph takes that
     * paragraph's place - a <table> inside a <p> is not valid HTML.
     *
     * @param array<string, string> $values
     */
    private function unwrapTable(string $html, array $values): string
    {
        $table = $values[AgreementCommissionFields::KEY] ?? '';
        if (!str_starts_with($table, '<table')) {
            return $html;
        }

        return preg_replace_callback(
            '#<(p|div)\b[^>]*>(?:\s|&nbsp;)*\{\{\s*signer\.commission_table\s*\}\}(?:\s|&nbsp;|<br\s*/?>)*</\1>#i',
            fn () => $table,
            $html
        ) ?? $html;
    }

    /** @return array<string, string> */
    private function signer(User $user): array
    {
        if ($user->entity_type === Partner::class && $partner = $user->partner) {
            return [
                'signer.name' => (string) ($partner->full_name ?? ''),
                'signer.email' => (string) ($partner->email ?? ''),
                'signer.phone' => (string) ($partner->phone_number ?? ''),
                'signer.address' => $this->partnerAddress($partner),
                'signer.business_name' => (string) ($partner->company_name ?? ''),
                'signer.business_address' => $this->partnerAddress($partner),
            ];
        }

        $borrower = $user->borrower;

        return [
            'signer.name' => $this->join(' ', [$borrower?->first_name, $borrower?->middle_name, $borrower?->last_name]),
            'signer.email' => (string) ($borrower?->email ?? ''),
            'signer.phone' => (string) ($borrower?->phone_number ?? ''),
            'signer.address' => $this->address($borrower?->home_address, null, $borrower?->city, $borrower?->state, $borrower?->postal_code),
            'signer.business_name' => (string) ($borrower?->business_name ?? ''),
            'signer.business_address' => $this->address(
                $borrower?->business_address, null, $borrower?->business_city, $borrower?->business_state, $borrower?->business_postal_code
            ),
        ];
    }

    private function partnerAddress(Partner $partner): string
    {
        return $this->address($partner->address_line_1, $partner->address_line_2, $partner->city, $partner->state, $partner->postal_code);
    }

    /** "1 Main St, Suite 2, Austin, TX 78701", leaving out what is missing. */
    private function address(?string $line1, ?string $line2, ?string $city, ?string $state, ?string $postal): string
    {
        return $this->join(', ', [$line1, $line2, $city, $this->join(' ', [$state, $postal])]);
    }

    private function join(string $glue, array $parts): string
    {
        return implode($glue, array_filter(array_map(fn ($part) => trim((string) $part), $parts), fn ($part) => $part !== ''));
    }
}
