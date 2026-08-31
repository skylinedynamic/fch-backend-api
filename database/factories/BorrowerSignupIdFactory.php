<?php

namespace Database\Factories;

use App\Models\Borrower\BorrowerSignupId;
use App\Models\Partner\Partner;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<BorrowerSignupId>
 */
class BorrowerSignupIdFactory extends Factory
{
    protected $model = BorrowerSignupId::class;

    public function definition(): array
    {
        return [
            'signup_id' => Str::upper(Str::random(10)),
            'partner_id' => Partner::factory(),
        ];
    }
}
