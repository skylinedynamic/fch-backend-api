<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$text = file_get_contents('test_real_parser_text.txt');
$service = new App\Services\FCH\Common\Borrower\IsoftpullParserService();
$res = $service->parse($text);
file_put_contents('test_real_parser_output.json', json_encode($res, JSON_PRETTY_PRINT));
echo "Done.\n";
