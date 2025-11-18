<?php

use Illuminate\Support\Facades\Route;
use Dedoc\Scramble\Scramble;

Route::get('/', function () {
    return redirect()->url('/docs/admin');
});

Route::get('/testing', function () {
    return view('recaptcha');
});

// Register borrower API documentation routes
Scramble::registerUiRoute('docs/borrower', api: 'borrower');
Scramble::registerJsonSpecificationRoute('docs/borrower/api.json', api: 'borrower');

// Register combined admin documentation (admin + partner) under one URL
Scramble::registerUiRoute('docs/admin', api: 'admin');
Scramble::registerJsonSpecificationRoute('docs/admin/api.json', api: 'admin');
