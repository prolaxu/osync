<?php

use Illuminate\Support\Facades\Route;
use Nepicsoft\Osync\Http\SyncController;

Route::post(config('osync.route.uri'), SyncController::class)
    ->middleware(config('osync.route.middleware'))
    ->name('osync');
