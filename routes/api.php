<?php

use App\Http\Controllers\Api\BatchController;
use App\Http\Controllers\Api\MonitoringController;
use App\Http\Controllers\Api\NotificationController;
use Illuminate\Support\Facades\Route;

Route::get('/notifications', [NotificationController::class, 'index']);
Route::post('/notifications', [NotificationController::class, 'store']);
Route::post('/notifications/batch', [NotificationController::class, 'storeBatch']);
Route::get('/notifications/{notification}', [NotificationController::class, 'show']);
Route::post('/notifications/{notification}/cancel', [NotificationController::class, 'cancel']);
Route::post('/notifications/{notification}/retry', [NotificationController::class, 'retry']);

Route::get('/batches/{batch}', [BatchController::class, 'show']);

Route::get('/metrics', [MonitoringController::class, 'metrics']);
Route::get('/health', [MonitoringController::class, 'health']);
