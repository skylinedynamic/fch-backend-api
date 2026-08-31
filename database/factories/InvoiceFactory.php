<?php

namespace Database\Factories;

use App\Models\Borrower\Borrower;
use App\Models\Invoice\Invoice;
use App\Models\Partner\Partner;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Invoice>
 */
class InvoiceFactory extends Factory
{
    protected $model = Invoice::class;

    public function definition(): array
    {
        return [
            'invoice_id' => 'INV-'.fake()->unique()->numerify('######'),
            'partner_id' => Partner::factory(),
            'borrower_id' => null,
            'subscription_id' => null,
            'entity_type' => Borrower::class,
            'entity_id' => null,
            'customer_name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'country' => 'US',
            'payment_amount' => 100.00,
            'month_count' => null,
            'payment_date' => null,
            'status' => Invoice::STATUS_UNPAID,
            'type' => Invoice::INVOICE_TYPE_ENROLLMENT,
            'is_partner_paying' => false,
            'currency' => 'USD',
            'currency_symbol' => '$',
            'conversion_rate' => 1.00,
            'is_platform_fee' => false,
            'details' => null,
            'note' => null,
            'created_by' => null,
            'updated_by' => null,
        ];
    }

    /**
     * Invoice owed by a specific Borrower (entity + borrower_id + partner_id all aligned).
     */
    public function forBorrower(Borrower $borrower): static
    {
        return $this->state(fn (array $attributes) => [
            'entity_type' => Borrower::class,
            'entity_id' => $borrower->id,
            'borrower_id' => $borrower->id,
            'partner_id' => $borrower->partner_id,
            'customer_name' => trim($borrower->first_name.' '.$borrower->last_name),
            'email' => $borrower->email,
        ]);
    }

    /**
     * Invoice owed by a specific Partner (partner paying host, e.g. platform fee).
     */
    public function forPartner(Partner $partner): static
    {
        return $this->state(fn (array $attributes) => [
            'entity_type' => Partner::class,
            'entity_id' => $partner->id,
            'borrower_id' => null,
            'partner_id' => $partner->id,
            'customer_name' => $partner->full_name,
            'email' => $partner->email,
            'is_partner_paying' => true,
        ]);
    }

    public function unpaid(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => Invoice::STATUS_UNPAID,
        ]);
    }

    public function paid(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => Invoice::STATUS_PAID,
            'payment_date' => now()->toDateString(),
        ]);
    }

    public function processing(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => Invoice::STATUS_PROCESSING,
        ]);
    }

    public function borrowerFee(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => Invoice::INVOICE_TYPE_BORROWER_FEE,
            'is_partner_paying' => true,
        ]);
    }
}
