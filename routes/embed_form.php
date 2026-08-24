<?php

/**
 * ============================================================
 *  Embedded Form System — Standalone Route File
 * ============================================================
 *
 *  This file is fully self-contained.
 *  Removing this file and its entry in bootstrap/app.php will have
 *  ZERO effect on any other part of the application.
 *
 *  Routes registered:
 *
 *  PUBLIC (no auth):
 *    GET  /api/v1/embed/config/{key}          — load partner branding & form title
 *    POST /api/v1/embed/submit/{key}           — submit a loan application
 *
 *  AUTHENTICATED (auth:api):
 *    GET  /api/v1/common/embed-forms/config              — get config + embed snippet
 *    PUT  /api/v1/common/embed-forms/config              — update config
 *    POST /api/v1/common/embed-forms/config/generate-key — regenerate public key
 *    POST /api/v1/common/embed-forms/submissions/fetch   — DataTable list
 *    GET  /api/v1/common/embed-forms/submissions/{id}    — single submission detail
 *    PUT  /api/v1/common/embed-forms/submissions/{id}/status — update status
 *    GET  /api/v1/common/embed-forms/attachments/{id}/download — download file
 * ============================================================
 */

use App\Http\Controllers\Api\V1\FCH\Common\EmbedForm\EmbedFormController;
use App\Http\Controllers\Api\V1\Global\Website\EmbedForm\PublicEmbedFormController;
use Illuminate\Support\Facades\Route;

// ──────────────────────────────────────────────────────────────
// PUBLIC — No authentication required
// ──────────────────────────────────────────────────────────────
Route::prefix('v1/embed')->group(function () {
    Route::get('config/{key}', [PublicEmbedFormController::class, 'config']);
    Route::post('submit/{key}', [PublicEmbedFormController::class, 'submit']);
});

// ──────────────────────────────────────────────────────────────
// AUTHENTICATED — Requires valid API token (auth:api)
// ──────────────────────────────────────────────────────────────
Route::prefix('v1/common/embed-forms')
    ->middleware('auth:api')
    ->group(function () {
        // Config management
        Route::get('config', [EmbedFormController::class, 'getConfig']);
        Route::put('config', [EmbedFormController::class, 'updateConfig']);
        Route::post('config/generate-key', [EmbedFormController::class, 'regenerateKey']);

        // Submissions
        Route::post('submissions/fetch', [EmbedFormController::class, 'fetchSubmissions']);
        Route::get('submissions/{id}', [EmbedFormController::class, 'showSubmission']);
        Route::put('submissions/{id}/status', [EmbedFormController::class, 'updateStatus']);

        // Attachments
        Route::get('attachments/{id}/download', [EmbedFormController::class, 'downloadAttachment']);
    });
