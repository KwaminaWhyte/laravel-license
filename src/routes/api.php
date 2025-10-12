<?php

use Illuminate\Support\Facades\Route;
use Westel\License\Http\Controllers\LicenseApiController;

/*
|--------------------------------------------------------------------------
| License API Routes
|--------------------------------------------------------------------------
|
| These routes provide external API endpoints for license validation,
| activation, and management. They are designed for server mode and
| provide JSON responses for client applications.
|
| All routes are prefixed with the configured API prefix (default: api/license)
| and include CORS support when enabled in configuration.
|
*/

Route::middleware(['cors'])->group(function () {

    // Primary validation and activation endpoints
    Route::post('validate', [LicenseApiController::class, 'validate'])
        ->name('license.api.validate');

    Route::post('activate', [LicenseApiController::class, 'activate'])
        ->name('license.api.activate');

    Route::post('deactivate', [LicenseApiController::class, 'deactivate'])
        ->name('license.api.deactivate');

    Route::post('heartbeat', [LicenseApiController::class, 'heartbeat'])
        ->name('license.api.heartbeat');

    // Status and information endpoints
    Route::get('status', [LicenseApiController::class, 'status'])
        ->name('license.api.status');

    Route::get('info/{licenseKey}', [LicenseApiController::class, 'info'])
        ->name('license.api.info');

    // Feature management endpoints
    Route::get('features', [LicenseApiController::class, 'features'])
        ->name('license.api.features');

    Route::post('validate-feature', [LicenseApiController::class, 'validateFeature'])
        ->name('license.api.validate-feature');

    // Product and tier information
    Route::get('tiers', [LicenseApiController::class, 'tiers'])
        ->name('license.api.tiers');

    // Analytics and statistics (optional endpoints)
    Route::get('analytics', [LicenseApiController::class, 'analytics'])
        ->name('license.api.analytics');

    Route::get('validation-stats', [LicenseApiController::class, 'validationStats'])
        ->name('license.api.validation-stats');

    // Hardware fingerprinting utility
    Route::get('hardware-info', [LicenseApiController::class, 'hardwareInfo'])
        ->name('license.api.hardware-info');
});
