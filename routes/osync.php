<?php

use Illuminate\Support\Facades\Route;
use Nepicsoft\Osync\Http\Compression;
use Nepicsoft\Osync\Http\SyncController;

Route::post(config('osync.route.uri'), SyncController::class)
    ->middleware([
        ...config('osync.route.middleware'),
        ...(config('osync.compression', true) ? [Compression::class] : []),
    ])
    ->name('osync');
