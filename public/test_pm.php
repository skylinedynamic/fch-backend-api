<?php
require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

$methods = \App\Models\PaymentMethod\PaymentMethod::where('partner_id', 20)->get();
$mapped = [];
foreach ($methods as $method) {
    $mapped[$method->key] = $method->value;
}
echo json_encode(['data' => $mapped]);
