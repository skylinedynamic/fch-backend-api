<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Services\FCH\Common\Borrower\IsoftpullParserService;
use Smalot\PdfParser\Parser;

$pdfPath = 'C:\\laragon\\www\\react-fch\\SCHWARTZ, CHARLES J. - CBR_060626.pdf';
$parser = new Parser();
$pdf = $parser->parseFile($pdfPath);
$text = $pdf->getText();

$service = new IsoftpullParserService();
$result = $service->parse($text);

file_put_contents('test_real_parser_output.json', json_encode($result, JSON_PRETTY_PRINT));
echo "Done.\n";
