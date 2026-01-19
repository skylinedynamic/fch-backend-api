<?php

namespace Database\Seeders;

use App\Models\Invoice\Invoice;
use App\Models\Partner\Partner;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class InvoiceSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $partners = Partner::all();

        if ($partners->isEmpty()) {
            return;
        }

        foreach ($partners as $partner) {
            // Create 5-15 invoices for each partner
            $count = rand(5, 15);

            for ($i = 0; $i < $count; $i++) {
                $today = now()->format('Ymd');
                $sequence = str_pad($i + 1, 3, '0', STR_PAD_LEFT);
                Invoice::create([
                    'invoice_id' => "INV-{$today}{$sequence}",
                    'partner_id' => $partner->id,
                    'borrower_id' => null,
                    'customer_name' => 'Customer ' . Str::random(5),
                    'email' => 'customer' . Str::random(3) . '@example.com',
                    'country' => 'USA',
                    'payment_amount' => rand(100, 5000),
                    'payment_date' => Carbon::now()->subDays(rand(0, 60)),
                    'status' => $this->getRandomStatus(),
                ]);
            }
        }
    }

    private function getRandomStatus()
    {
        $statuses = ['Paid', 'Pending', 'Overdue', 'Canceled', 'Unpaid'];

        return $statuses[array_rand($statuses)];
    }
}
