<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\Global\FileManager\FileManagerController;

Route::prefix('v1/filemanager')->group(function () {
    Route::post('/list', [FileManagerController::class, 'index']);
    Route::post('/upload', [FileManagerController::class, 'upload']);
    Route::post('/folder', [FileManagerController::class, 'createFolder']);
    Route::put('/rename', [FileManagerController::class, 'rename']);
    Route::post('/delete', [FileManagerController::class, 'delete']);
    Route::post('/paste', [FileManagerController::class, 'paste']);
});
