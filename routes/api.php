<?php

use App\Http\Controllers\Api\V1\AlgeriaController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\BudgetController;
use App\Http\Controllers\Api\V1\CategoryController;
use App\Http\Controllers\Api\V1\CurrencyController;
use App\Http\Controllers\Api\V1\GoalController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\StatisticsController;
use App\Http\Controllers\Api\V1\TransactionController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::post('/auth/register', [AuthController::class, 'register']);
    Route::post('/auth/login', [AuthController::class, 'login']);
    Route::post('/auth/google', [AuthController::class, 'google']);
    Route::post('/auth/forgot-password', [AuthController::class, 'forgotPassword']);
    Route::post('/auth/reset-password', [AuthController::class, 'resetPassword']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/auth/me', [AuthController::class, 'me']);
        Route::put('/auth/profile', [AuthController::class, 'updateProfile']);
        Route::post('/auth/logout', [AuthController::class, 'logout']);

        Route::get('/categories', [CategoryController::class, 'index']);

        Route::apiResource('transactions', TransactionController::class);
        Route::apiResource('budgets', BudgetController::class);
        Route::apiResource('goals', GoalController::class);
        Route::post('/goals/{goal}/deposit', [GoalController::class, 'deposit']);

        Route::get('/statistics/monthly', [StatisticsController::class, 'monthly']);
        Route::get('/statistics/categories', [StatisticsController::class, 'categories']);

        Route::get('/currencies', [CurrencyController::class, 'index']);
        Route::get('/currencies/convert', [CurrencyController::class, 'convert']);
        Route::get('/currencies/history', [CurrencyController::class, 'history']);

        Route::get('/algeria/rates', [AlgeriaController::class, 'rates']);
        Route::get('/algeria/history', [AlgeriaController::class, 'history']);
        Route::get('/algeria/premium', [AlgeriaController::class, 'premium']);

        Route::get('/notifications', [NotificationController::class, 'index']);
        Route::post('/notifications/{notification}/read', [NotificationController::class, 'markRead']);
    });
});
