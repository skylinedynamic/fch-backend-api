<?php

namespace Database\Seeders;

use Carbon\Carbon;
use Illuminate\Support\Str;
use App\Models\Invoice\Invoice;
use App\Models\Partner\Partner;
use Illuminate\Database\Seeder;
use App\Models\Borrower\Borrower;

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

        // Get all borrowers for linking to invoices
        $borrowers = Borrower::all();

        foreach ($partners as $partner) {
            // Create 5-15 invoices for each partner
            $count = rand(5, 15);

            // Get borrowers for this specific partner
            $partnerBorrowers = $borrowers->where('partner_id', $partner->id);

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

                // Randomly assign a borrower (50% chance if borrowers exist for this partner)
                $borrower = null;
                $customerName = 'Customer ' . Str::random(5);
                $customerEmail = 'customer' . Str::random(3) . '@example.com';

                if ($partnerBorrowers->isNotEmpty() && rand(0, 1) === 1) {
                    $borrower = $partnerBorrowers->random();
                    $customerName = trim($borrower->first_name . ' ' . $borrower->last_name);
                    $customerEmail = $borrower->email ?? $customerEmail;
                }

                Invoice::create([
                    'invoice_id' => "INV-{$today}{$sequence}",
                    'partner_id' => $partner->id,
                    'borrower_id' => $borrower?->id,
                    'customer_name' => $customerName,
                    'email' => $customerEmail,
                    'country' => 'USA',
                    'payment_amount' => $totalAmount,
                    'payment_date' => Carbon::now()->subDays(rand(0, 60)),
                    'status' => $this->getRandomStatus(),
                    'details' => [
                        'items' => $items,
                        'billing_address' => [
                            'name' => $customerName,
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



    private function getRandomStatus()
    {
        $statuses = [Invoice::STATUS_PAID, Invoice::STATUS_PENDING, Invoice::STATUS_OVERDUE, Invoice::STATUS_CANCELED, Invoice::STATUS_UNPAID];
        return $statuses[array_rand($statuses)];
    }
}
