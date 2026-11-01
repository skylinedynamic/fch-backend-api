<?php

namespace App\Services\FCH\Common\Invoice;

use App\Models\Borrower\Borrower;
use App\Models\Employee\Employee;
use App\Models\Invoice\Invoice;
use App\Models\Partner\Partner;
use App\Models\User\User;

/**
 * Resolves who an invoice is billed from and billed to.
 *
 * entity_type/entity_id is whoever PAYS the invoice, so it is always "Billed
 * To". "Billed From" is whoever is paid:
 *
 *   type = borrower_fee      the partner's debt to the host   -> host
 *   entity_type = Partner    the host is billing the partner  -> host
 *   anything else            the partner billing its client   -> invoices.partner_id
 *
 * Both sides come back in one contact shape, so a consumer renders them the
 * same way whatever kind of model is behind them.
 */
class InvoicePartyResolver
{
    /**
     * @return array{billed_from: array<string, ?string>, billed_to: array<string, ?string>}
     */
    public function resolve(Invoice $invoice): array
    {
        return [
            'billed_from' => $this->billedFrom($invoice),
            'billed_to' => $this->billedTo($invoice),
        ];
    }

    /**
     * @return array<string, ?string>
     */
    public function billedFrom(Invoice $invoice): array
    {
        $billsHost = $invoice->type === Invoice::INVOICE_TYPE_BORROWER_FEE
            || $invoice->entity_type === Partner::class;

        $partner = $billsHost
            ? Partner::host()
            : ($invoice->partner_id ? Partner::find($invoice->partner_id) : null);

        // An invoice with no partner on it is still issued by someone.
        $partner ??= Partner::host();

        return $partner ? $this->fromPartner($partner) : $this->contact();
    }

    /**
     * @return array<string, ?string>
     */
    public function billedTo(Invoice $invoice): array
    {
        // A list eager-loads the payer; anything else loads it here rather than
        // tripping the lazy-loading guard.
        $entity = $invoice->loadMissing('entity')->entity;

        return match (true) {
            $entity instanceof Partner => $this->fromPartner($entity),
            $entity instanceof Borrower => $this->fromBorrower($entity),
            $entity instanceof Employee => $this->fromEmployee($entity),
            $entity instanceof User => $this->fromUser($entity),
            // The payer is gone (or of an unknown kind): the name, email and
            // country snapshotted onto the invoice are all that is left.
            default => $this->contact(
                name: $invoice->customer_name,
                email: $invoice->email,
                country: $invoice->country,
            ),
        };
    }

    private function fromPartner(Partner $partner): array
    {
        return $this->contact(
            name: $partner->full_name,
            companyName: $partner->company_name,
            email: $partner->email,
            phone: $partner->phone_number,
            address: $this->joinLines($partner->address_line_1, $partner->address_line_2),
            city: $partner->city,
            state: $partner->state,
            postalCode: $partner->postal_code,
            country: $partner->country,
        );
    }

    /**
     * A borrower is billed as their company: the business name and the business
     * address, falling back to their home address only when they gave no
     * business one. The two sets are never mixed, so a home city cannot end up
     * beside a business street.
     */
    private function fromBorrower(Borrower $borrower): array
    {
        $hasBusinessAddress = filled($borrower->business_address)
            || filled($borrower->business_city)
            || filled($borrower->business_state)
            || filled($borrower->business_postal_code);

        return $this->contact(
            name: $borrower->full_name,
            companyName: $borrower->business_name,
            email: $borrower->email ?: $borrower->business_email,
            phone: $borrower->phone_number ?: $borrower->business_phone,
            address: $hasBusinessAddress ? $borrower->business_address : $borrower->home_address,
            city: $hasBusinessAddress ? $borrower->business_city : $borrower->city,
            state: $hasBusinessAddress ? $borrower->business_state : $borrower->state,
            postalCode: $hasBusinessAddress ? $borrower->business_postal_code : $borrower->postal_code,
            country: $hasBusinessAddress ? ($borrower->business_country ?: $borrower->country) : $borrower->country,
        );
    }

    private function fromEmployee(Employee $employee): array
    {
        return $this->contact(
            name: $employee->full_name,
            email: $employee->email,
            phone: $employee->phone_number_1 ?: $employee->phone_number_2,
            address: $employee->address,
            city: $employee->city,
            state: $employee->state,
            postalCode: $employee->postal_code,
            country: $employee->country,
        );
    }

    private function fromUser(User $user): array
    {
        return $this->contact(
            name: $user->name,
            email: $user->email,
            phone: $user->phone_number,
        );
    }

    private function joinLines(?string ...$lines): ?string
    {
        $present = array_filter($lines, 'filled');

        return $present ? implode(', ', $present) : null;
    }

    /**
     * The one contact shape both sides of an invoice are returned in.
     */
    private function contact(
        ?string $name = null,
        ?string $companyName = null,
        ?string $email = null,
        ?string $phone = null,
        ?string $address = null,
        ?string $city = null,
        ?string $state = null,
        ?string $postalCode = null,
        ?string $country = null,
    ): array {
        return [
            'name' => $name ?: null,
            'company_name' => $companyName ?: null,
            'email' => $email ?: null,
            'phone' => $phone ?: null,
            'address' => $address ?: null,
            'city' => $city ?: null,
            'state' => $state ?: null,
            'postal_code' => $postalCode ?: null,
            'country' => $country ?: null,
        ];
    }
}
