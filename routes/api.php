<?php

use App\Http\Controllers\Api\AttendanceController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BioTimeSyncController;
use App\Http\Controllers\Api\CatalogController;
use App\Http\Controllers\Api\EmployeeController;
use App\Http\Controllers\Api\HistoryController;
use App\Http\Controllers\Api\LeaveController;
use App\Http\Controllers\Api\ScheduleController;
use App\Http\Controllers\Api\UserController;
use Illuminate\Support\Facades\Route;

Route::post('/auth/login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/auth/me', [AuthController::class, 'me']);
    Route::post('/auth/logout', [AuthController::class, 'logout']);

    // 1. Lectura y Consulta General (Administrador, Recursos Humanos, Supervisor / Nómina, Auditor STPS)
    Route::get('/biotime/status', [BioTimeSyncController::class, 'status']);
    Route::get('/attendance/state', [AttendanceController::class, 'state']);
    Route::get('/history/imports', [HistoryController::class, 'imports']);
    Route::get('/history/corrections', [HistoryController::class, 'correctionLogs']);
    Route::get('/employees', [EmployeeController::class, 'index']);
    Route::get('/catalogs', [CatalogController::class, 'index']);
    Route::get('/schedules', [ScheduleController::class, 'index']);
    Route::get('/leaves', [LeaveController::class, 'index']);

    // 2. Operación de Asistencia: Sincronizar Checadas y Editar/Validar Jornadas
    // Permitido a: Administrador, Recursos Humanos, Supervisor de Horarios (No permitido a Auditor STPS)
    Route::middleware('role:Administrador,Recursos Humanos,Supervisor de Horarios,Supervisor / Nómina')->group(function () {
        Route::post('/biotime/sync-transactions', [BioTimeSyncController::class, 'syncTransactions']);
        Route::post('/attendance/import', [AttendanceController::class, 'importEvents']);
        Route::post('/attendance/corrections', [AttendanceController::class, 'saveCorrection']);
        Route::post('/attendance/corrections/reset', [AttendanceController::class, 'resetCorrection']);
    });

    // 3. Administración de Personal, Catálogos BioTime y Limpieza / Reversión de Cortes
    // Permitido a: Administrador y Recursos Humanos
    Route::middleware('role:Administrador,Recursos Humanos')->group(function () {
        Route::post('/biotime/sync-catalogs', [BioTimeSyncController::class, 'syncCatalogs']);
        Route::delete('/attendance/clear', [AttendanceController::class, 'clearAll']);
        Route::post('/history/imports/{importBatch}/restore', [HistoryController::class, 'restoreImportBatch']);
        Route::delete('/history/imports/{importBatch}', [HistoryController::class, 'deleteImportBatch']);

        Route::post('/employees', [EmployeeController::class, 'store']);
        Route::put('/employees/{employee}', [EmployeeController::class, 'update']);
        Route::delete('/employees/{employee}', [EmployeeController::class, 'destroy']);

        Route::post('/catalogs/import', [CatalogController::class, 'importCsv']);
        Route::post('/catalogs/{type}', [CatalogController::class, 'store']);
        Route::put('/catalogs/{type}/{id}', [CatalogController::class, 'update']);
        Route::delete('/catalogs/{type}/{id}', [CatalogController::class, 'destroy']);

        // Asignación Visual de Horarios (Empleados y Departamentos) con Sync Bidireccional
        Route::post('/schedules/sync', [ScheduleController::class, 'syncWithBioTime']);
        Route::post('/schedules/assign-employees', [ScheduleController::class, 'assignEmployees']);
        Route::post('/schedules/assign-department', [ScheduleController::class, 'assignDepartment']);
        Route::patch('/schedules/timetables/{timetable}/break', [ScheduleController::class, 'updateTimetableBreak']);
        Route::delete('/schedules/employee/{employeeSchedule}', [ScheduleController::class, 'destroyEmployeeSchedule']);

        // Vacaciones, Incapacidades IMSS y Permisos
        Route::post('/leaves', [LeaveController::class, 'store']);
        Route::post('/leaves/sync', [LeaveController::class, 'syncWithBioTime']);
        Route::delete('/leaves/{employeeLeave}', [LeaveController::class, 'destroy']);
    });

    // 4. Gestión de Usuarios y Control de Acceso
    // Exclusivo para: Administrador
    Route::middleware('role:Administrador')->group(function () {
        Route::get('/users', [UserController::class, 'index']);
        Route::post('/users', [UserController::class, 'store']);
        Route::put('/users/{user}', [UserController::class, 'update']);
        Route::delete('/users/{user}', [UserController::class, 'destroy']);
    });
});
