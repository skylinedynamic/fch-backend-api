<?php

namespace Database\Factories;

use App\Models\Borrower\Borrower;
use App\Models\Borrower\BorrowerBankAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BorrowerBankAccount>
 *
 * Note: the `borrower_bank_accounts` table only has `borrower_id`, `bank_name`
 * and `account_open_date` columns (see its migration) — it does NOT store
 * account/routing numbers, those live in PaymentMethodBankDetail (a different
 * domain).
 */
class BorrowerBankAccountFactory extends Factory
{
    protected $model = BorrowerBankAccount::class;

    public function definition(): array
    {
        return [
            'borrower_id' => Borrower::factory(),
            'bank_name' => fake()->company().' Bank',
            'account_open_date' => fake()->date('Y-m-d', '-2 years'),
        ];
    }
}
