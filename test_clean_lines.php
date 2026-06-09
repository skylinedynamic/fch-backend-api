<?php
$text = file_get_contents('test_real_parser_text.txt');
$text = str_replace(
    ["\xc2\xa0", "\xe2\x80\x93", "\xe2\x80\x94", "\xe2\x80\x98", "\xe2\x80\x99"],
    [' ', '-', '--', "'", "'"],
    $text
);
$lines = explode("\n", $text);
$clean = [];
foreach ($lines as $line) {
    $trimmed = trim($line);
    if ($trimmed !== '') {
        $clean[] = $trimmed;
    }
}
print_r(array_slice($clean, 10, 20));
