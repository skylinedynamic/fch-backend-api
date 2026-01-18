<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Invoice\Invoice;
use App\Services\FCH\InvoiceService;

$invoices = Invoice::orderBy('id', 'asc')->get();
$nextId = 1001;

foreach ($invoices as $i) {
    echo "Updating Invoice ID: {$i->id}..." . PHP_EOL;
    if (empty($i->invoice_id)) {
        $i->invoice_id = 'INV-' . ($nextId++);
    }
    if (empty($i->customer_name)) {
        $i->customer_name = "Customer " . $i->id;
    }
    if (empty($i->email)) {
        $i->email = "customer{$i->id}@example.com";
    }
    if (empty($i->country)) {
        $i->country = "USA";
    }
    if (empty($i->cycle)) {
        $i->cycle = 'Monthly';
    }
    $i->save();
}

echo "Done populating " . $invoices->count() . " invoices." . PHP_EOL;
