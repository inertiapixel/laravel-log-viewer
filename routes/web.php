<?php

/*
 * InertiaPixel Log Viewer routes. Loaded by LogViewerServiceProvider inside
 * a group that applies the configured domain, prefix, name prefix and
 * middleware (plus the package's SecureHeaders and Authorize middleware).
 *
 * Every route is a read-only GET.
 *
 * @var bool $downloads
 */

use Illuminate\Support\Facades\Route;
use InertiaPixel\LaravelLogViewer\Http\Controllers\DashboardController;
use InertiaPixel\LaravelLogViewer\Http\Controllers\DownloadController;
use InertiaPixel\LaravelLogViewer\Http\Controllers\EntryController;
use InertiaPixel\LaravelLogViewer\Http\Middleware\Authorize;
use InertiaPixel\LaravelLogViewer\LogViewer;

Route::get('/', [DashboardController::class, 'index'])->name('index');

Route::get('results', [DashboardController::class, 'results'])->name('results');

Route::get('entries/{file}/{offset}', EntryController::class)
    ->where(['file' => '[a-f0-9]{16}', 'offset' => '[0-9]{1,18}'])
    ->name('entry');

if ($downloads) {
    Route::get('files/{file}/download', DownloadController::class)
        ->where('file', '[a-f0-9]{16}')
        ->middleware(Authorize::class.':'.LogViewer::ABILITY_DOWNLOAD)
        ->name('download');
}
