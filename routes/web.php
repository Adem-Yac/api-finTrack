<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return response()->json([
        'name' => 'FinTrack API',
        'health' => '/up',
        'api' => '/api/v1',
    ]);
});
