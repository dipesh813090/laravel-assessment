<?php

use App\Http\Controllers\BulkOnboardController;
use Illuminate\Support\Facades\Route;

Route::post('/bulk-onboard', [BulkOnboardController::class, 'store']);

