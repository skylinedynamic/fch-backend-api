<?php

namespace Database\Factories;

use App\Models\Borrower\Borrower;
use App\Models\Invoice\Invoice;
use App\Models\Partner\Partner;
use App\Models\Payment\Payment;
use App\Models\PaymentMethod\PaymentProvider;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Payment>
 */
class PaymentFactory extends Factory
{
    protected $model = Payment::class;

    public function definition(): array
    {
        return [
            'partner_id' => Partner::factory(),
            'borrower_id' => null,
            'invoice_id' => Invoice::factory(),
            'entity_type' => Borrower::class,
            'entity_id' => null,
            'transaction_id' => 'TX_'.fake()->unique()->numerify('##########'),
            'payment_from' => PaymentProvider::STRIPE,
            'payment_type' => PaymentProvider::TYPE_CARD,
            'amount' => 100.00,
            'details' => [],
            'status' => Payment::STATUS_PENDING,
            'transaction_date' => now(),
            'is_partner_paying' => false,
            'is_manual' => false,
            'is_environment' => PaymentProvider::ENVIRONMENT_SANDBOX,
            'currency' => 'USD',
            'currency_symbol' => '$',
            'conversion_rate' => 1.00,
            'created_by' => null,
        ];
    }

    public function forBorrower(Borrower $borrower): static
    {
        return $this->state(fn (array $attributes) => [
            'entity_type' => Borrower::class,
            'entity_id' => $borrower->id,
            'borrower_id' => $borrower->id,
            'partner_id' => $borrower->partner_id,
        ]);
    }

    public function forPartner(Partner $partner): static
    {
        return $this->state(fn (array $attributes) => [
            'entity_type' => Partner::class,
            'entity_id' => $partner->id,
            'borrower_id' => null,
            'partner_id' => $partner->id,
            'is_partner_paying' => true,
        ]);
    }

    public function succeeded(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => Payment::STATUS_SUCCEEDED,
        ]);
    }

    public function processing(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => Payment::STATUS_PROCESSING,
            'is_manual' => true,
        ]);
    }

    public function manual(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_manual' => true,
            'payment_from' => PaymentProvider::BANK_TRANSFER,
            'payment_type' => PaymentProvider::BANK_TRANSFER,
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => Payment::STATUS_FAILED,
        ]);
    }
}
