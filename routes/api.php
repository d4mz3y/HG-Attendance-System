<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\AttendanceController;
use App\Http\Controllers\Api\AuditController;
use App\Http\Controllers\Api\BiometricController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\DepartmentScheduleController;
use App\Http\Controllers\Api\LeaveController;
use App\Http\Controllers\Api\LookupController;
use App\Http\Controllers\Api\PublicHolidayController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\ScanController;
use App\Http\Controllers\Api\ScheduleController;
use App\Http\Controllers\Api\SettingsController;
use App\Http\Controllers\Api\StaffCodesController;
use App\Http\Controllers\Api\StaffController;
use Illuminate\Support\Facades\Route;

Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:20,1');
Route::post('/scan', [ScanController::class, 'store'])->middleware('throttle:120,1');
Route::post('/biometric/punch', [BiometricController::class, 'punch'])->middleware('throttle:120,1');

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/user', [AuthController::class, 'user']);

    Route::get('/dashboard/today', [DashboardController::class, 'today']);

    Route::get('/staff', [StaffController::class, 'index']);
    Route::get('/staff/next-id', [StaffController::class, 'nextId']);
    Route::post('/staff', [StaffController::class, 'store']);
    Route::post('/staff/import', [StaffController::class, 'import']);
    Route::get('/staff/export', [StaffController::class, 'export']);
    Route::get('/staff/{staff}/codes/qr', [StaffCodesController::class, 'qr']);
    Route::get('/staff/{staff}/codes/barcode', [StaffCodesController::class, 'barcode']);
    Route::get('/staff/{staff}', [StaffController::class, 'show']);
    Route::put('/staff/{staff}', [StaffController::class, 'update']);
    Route::delete('/staff/{staff}', [StaffController::class, 'destroy']);

    Route::get('/schedules/{staff}', [ScheduleController::class, 'forStaff']);
    Route::put('/schedules/{staff}', [ScheduleController::class, 'upsert']);
    Route::get('/schedules/department/{department}', [DepartmentScheduleController::class, 'forDepartment']);
    Route::put('/schedules/department/{department}', [DepartmentScheduleController::class, 'upsert']);

    Route::get('/attendances', [AttendanceController::class, 'index']);
    Route::patch('/attendances/{attendance}', [AttendanceController::class, 'update']);
    Route::get('/attendances/{attendance}/audits', [AuditController::class, 'forAttendance']);

    Route::get('/leaves', [LeaveController::class, 'index']);
    Route::post('/leaves', [LeaveController::class, 'store']);
    Route::get('/leaves/upcoming/{staff}', [LeaveController::class, 'upcoming']);
    Route::get('/leaves/{leave}', [LeaveController::class, 'show']);
    Route::put('/leaves/{leave}', [LeaveController::class, 'update']);
    Route::delete('/leaves/{leave}', [LeaveController::class, 'destroy']);

    Route::get('/public-holidays', [PublicHolidayController::class, 'index']);
    Route::post('/public-holidays', [PublicHolidayController::class, 'store']);
    Route::get('/public-holidays/upcoming', [PublicHolidayController::class, 'upcoming']);
    Route::get('/public-holidays/{publicHoliday}', [PublicHolidayController::class, 'show']);
    Route::put('/public-holidays/{publicHoliday}', [PublicHolidayController::class, 'update']);
    Route::delete('/public-holidays/{publicHoliday}', [PublicHolidayController::class, 'destroy']);

    Route::get('/settings', [SettingsController::class, 'show']);
    Route::put('/settings', [SettingsController::class, 'update']);

    Route::get('/lookups/departments', [LookupController::class, 'departments']);
    Route::get('/lookups/staff', [LookupController::class, 'staffOptions']);
    Route::get('/lookups/branches', [LookupController::class, 'branches']);
    Route::get('/lookups/companies', [LookupController::class, 'companies']);
    Route::put('/lookups/departments', [LookupController::class, 'updateDepartments']);
    Route::put('/lookups/branches', [LookupController::class, 'updateBranches']);
    Route::put('/lookups/companies', [LookupController::class, 'updateCompanies']);

    Route::get('/reports', [ReportController::class, 'index']);
    Route::post('/reports/export', [ReportController::class, 'export']);
    Route::get('/reports/export/pdf', [ReportController::class, 'exportPdf']);
    Route::get('/reports/compliance', [ReportController::class, 'compliance']);
    Route::get('/reports/comparisons', [ReportController::class, 'comparisons']);

    Route::post('/scan/sync', [ScanController::class, 'sync']);
    Route::get('/scan/pending', [ScanController::class, 'pending']);
    Route::get('/audits', [AuditController::class, 'index']);
});
