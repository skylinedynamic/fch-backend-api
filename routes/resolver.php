<?php

use App\Http\Controllers\DomainResolverController;

Route::get('/resolve-custom-domain', [DomainResolverController::class, 'resolve'])->name('resolver.resolve');