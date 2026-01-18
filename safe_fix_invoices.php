<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Invoice\Invoice;

$invoices = Invoice::all();
$count = 0;

foreach ($invoices as $index => $i) {
    echo "Processing ID: {$i->id}..." . PHP_EOL;

    // Set a unique invoice_id regardless of unique constraint (temporary format)
    $i->invoice_id = 'INV-' . (1000 + $i->id);

    if (empty($i->customer_name)) $i->customer_name = "Test Customer " . $i->id;
    if (empty($i->email)) $i->email = "test{$i->id}@example.com";
    if (empty($i->country)) $i->country = "USA";
    if (empty($i->payment_amount)) $i->payment_amount = 100.00;
    if (empty($i->payment_date)) $i->payment_date = date('Y-m-d');
    if (empty($i->status)) $i->status = 'Pending';
    if (empty($i->cycle)) $i->cycle = 'Monthly';
    if (empty($i->partner_id)) $i->partner_id = 1; // Default to 1 for testing

    try {
        $i->save();
        $count++;
    } catch (\Exception $e) {
        echo "Error saving ID {$i->id}: " . $e->getMessage() . PHP_EOL;
    }
}

echo "Successfully updated $count invoices." . PHP_EOL;
