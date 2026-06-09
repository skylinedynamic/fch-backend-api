<?php

namespace App\Services\FCH\Common\Borrower;

interface CreditProfileParserInterface
{
    public function parse(string $text): array;
}