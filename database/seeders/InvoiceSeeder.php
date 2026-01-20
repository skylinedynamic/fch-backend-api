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

                $itemCount = rand(1, 4);
                $items = [];
                $totalAmount = 0;

                $products = [
                    ['desc' => 'Business Funding', 'rate' => 299],
                    ['desc' => 'New Customer Onboard', 'rate' => 2500],
                    ['desc' => 'Credit Repair Service', 'rate' => 499],
                    ['desc' => 'Consultation Fee', 'rate' => 150],
                ];

                for ($j = 0; $j < $itemCount; $j++) {
                    $product = $products[array_rand($products)];
                    $qty = rand(1, 2);
                    $amount = $product['rate'] * $qty;
                    $items[] = [
                        'description' => $product['desc'],
                        'rate' => $product['rate'],
                        'quantity' => $qty,
                        'amount' => $amount,
                    ];
                    $totalAmount += $amount;
                }

                Invoice::create([
                    'invoice_id' => "INV-{$today}{$sequence}",
                    'partner_id' => $partner->id,
                    'borrower_id' => null,
                    'customer_name' => 'Customer ' . Str::random(5),
                    'email' => 'customer' . Str::random(3) . '@example.com',
                    'country' => 'USA',
                    'payment_amount' => $totalAmount,
                    'payment_date' => Carbon::now()->subDays(rand(0, 60)),
                    'status' => $this->getRandomStatus(),
                    'cycle' => $this->getRandomCycle(),
                    'details' => [
                        'items' => $items,
                        'billing_address' => [
                            'name' => 'John Doe',
                            'street' => '123 Main St',
                            'city' => 'San Francisco',
                            'state' => 'CA',
                            'zip' => '94107',
                        ],
                        'payment_details' => [
                            'method' => 'Credit Card',
                            'last4' => '4242',
                            'brand' => 'Visa',
                        ]
                    ]
                ]);
            }
        }
    }

    private function getRandomCycle()
    {
        $cycles = [Invoice::CYCLE_MONTHLY, Invoice::CYCLE_ANNUAL, Invoice::CYCLE_QUARTERLY, Invoice::CYCLE_ONE_TIME];
        return $cycles[array_rand($cycles)];
    }

    private function getRandomStatus()
    {
        $statuses = ['Paid', 'Pending', 'Overdue', 'Canceled', 'Unpaid'];

        return $statuses[array_rand($statuses)];
    }
}
