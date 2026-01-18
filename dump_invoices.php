<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Invoice\Invoice;

echo "Total Invoices: " . Invoice::count() . PHP_EOL;
$withIds = Invoice::where('invoice_id', '!=', '')->whereNotNull('invoice_id')->get();
echo "Invoices with Invoice ID (" . $withIds->count() . "):" . PHP_EOL;
foreach ($withIds as $w) {
    echo "ID: {$w->id} | Invoice ID: '{$w->invoice_id}'" . PHP_EOL;
}
