<?php

use App\Http\Controllers\Api\AttendanceController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\EmployeeController;
use App\Http\Controllers\Api\HistoryController;
use Illuminate\Support\Facades\Route;

Route::post('/auth/login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/auth/me', [AuthController::class, 'me']);
    Route::post('/auth/logout', [AuthController::class, 'logout']);

    // Attendance core state, import, corrections
    Route::get('/attendance/state', [AttendanceController::class, 'state']);
    Route::post('/attendance/import', [AttendanceController::class, 'importEvents']);
    Route::post('/attendance/corrections', [AttendanceController::class, 'saveCorrection']);
    Route::post('/attendance/corrections/reset', [AttendanceController::class, 'resetCorrection']);
    Route::delete('/attendance/clear', [AttendanceController::class, 'clearAll']);

    // Histories
    Route::get('/history/imports', [HistoryController::class, 'imports']);
    Route::delete('/history/imports/{importBatch}', [HistoryController::class, 'deleteImportBatch']);
    Route::get('/history/corrections', [HistoryController::class, 'correctionLogs']);

    // Employees Catalog
    Route::get('/employees', [EmployeeController::class, 'index']);
    Route::post('/employees', [EmployeeController::class, 'store']);
    Route::put('/employees/{employee}', [EmployeeController::class, 'update']);
    Route::delete('/employees/{employee}', [EmployeeController::class, 'destroy']);
});
