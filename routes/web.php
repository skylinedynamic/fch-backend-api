<?php

use Illuminate\Support\Facades\Route;
use Dedoc\Scramble\Scramble;

Route::get('/', function () {
    return view('recaptcha');
});

// Register borrower API documentation routes
Scramble::registerUiRoute('docs/borrower', api: 'borrower');
Scramble::registerJsonSpecificationRoute('docs/borrower/api.json', api: 'borrower');
