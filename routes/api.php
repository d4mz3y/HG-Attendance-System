<?php

use App\Http\Controllers\Api\AttendanceController;
use App\Http\Controllers\Api\AuditController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BiometricController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\DepartmentScheduleController;
use App\Http\Controllers\Api\KioskDeviceController;
use App\Http\Controllers\Api\LeaveController;
use App\Http\Controllers\Api\LookupController;
use App\Http\Controllers\Api\PublicHolidayController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\ScanController;
use App\Http\Controllers\Api\ScheduleController;
use App\Http\Controllers\Api\SettingsController;
use App\Http\Controllers\Api\StaffCodesController;
use App\Http\Controllers\Api\StaffController;
use App\Http\Controllers\Api\SubscriptionController;
use App\Http\Controllers\Api\UserController;
use Illuminate\Support\Facades\Route;

Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login');

Route::get('/staff-photo/{staffId}', [StaffController::class, 'photo'])
    ->middleware(['signed', 'throttle:120,1'])
    ->name('staff.photo');

Route::get('/subscription/callback', [SubscriptionController::class, 'callback'])->middleware('throttle:30,1');
Route::post('/subscription/webhook', [SubscriptionController::class, 'webhook'])->middleware('throttle:60,1');

Route::post('/scan/reception/pair', [ScanController::class, 'pairReception'])->middleware('throttle:scan');
Route::middleware(['throttle:scan', 'scan.client'])->group(function (): void {
    Route::get('/scan/config', [ScanController::class, 'config']);
    Route::post('/scan', [ScanController::class, 'store']);
});
Route::middleware(['throttle:scan', 'device:kiosk'])->group(function (): void {
    Route::post('/scan/sync', [ScanController::class, 'sync']);
    Route::get('/scan/history', [ScanController::class, 'history']);
    Route::post('/scan/recover', [ScanController::class, 'recover']);
});
Route::post('/biometric/punch', [BiometricController::class, 'punch'])
    ->middleware(['throttle:scan', 'device:biometric']);
Route::get('/biometric/config', [BiometricController::class, 'config'])
    ->middleware(['throttle:scan', 'device:biometric']);

Route::middleware(['auth:sanctum', 'active'])->group(function (): void {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/user', [AuthController::class, 'user']);
    Route::post('/change-password', [AuthController::class, 'changePassword'])
        ->middleware(['permission:password.change_self', 'throttle:5,1']);

    Route::middleware(['password.changed', 'audit.mutations'])->group(function (): void {
        Route::middleware('permission:dashboard.view')->group(function (): void {
            Route::get('/dashboard/today', [DashboardController::class, 'today']);
            Route::get('/dashboard/sessions/{category}', [DashboardController::class, 'sessionCategory']);
        });

        Route::middleware('permission:staff.view')->group(function (): void {
            Route::get('/staff', [StaffController::class, 'index']);
        });
        Route::middleware('permission:staff.manage')->group(function (): void {
            Route::get('/staff/next-id', [StaffController::class, 'nextId']);
            Route::post('/staff', [StaffController::class, 'store']);
            Route::post('/staff/import', [StaffController::class, 'import']);
            Route::put('/staff/{staff}', [StaffController::class, 'update']);
            Route::delete('/staff/{staff}', [StaffController::class, 'destroy']);
            Route::get('/staff/{staff}/codes/qr', [StaffCodesController::class, 'qr']);
            Route::get('/staff/{staff}/codes/barcode', [StaffCodesController::class, 'barcode']);
        });
        Route::get('/staff/export', [StaffController::class, 'export'])->middleware('permission:staff.export');
        Route::get('/staff/{staff}/employment-history', [StaffController::class, 'employmentHistory'])->middleware('permission:staff.view');
        Route::get('/staff/{staff}/assignment-history', [StaffController::class, 'assignmentHistory'])->middleware('permission:staff.view');
        Route::get('/staff/{staff}', [StaffController::class, 'show'])->middleware('permission:staff.view');

        Route::middleware('permission:schedule.view')->group(function (): void {
            Route::get('/schedules/defaults', [ScheduleController::class, 'defaults']);
            Route::get('/schedules/{staff}', [ScheduleController::class, 'forStaff']);
            Route::get('/schedules/department/{department}', [DepartmentScheduleController::class, 'forDepartment']);
        });
        Route::middleware('permission:schedule.manage')->group(function (): void {
            Route::put('/schedules/{staff}', [ScheduleController::class, 'upsert']);
            Route::put('/schedules/department/{department}', [DepartmentScheduleController::class, 'upsert']);
            Route::delete('/schedules/{staff}', [ScheduleController::class, 'reset']);
            Route::delete('/schedules/department/{department}', [DepartmentScheduleController::class, 'reset']);
        });

        Route::get('/attendances', [AttendanceController::class, 'index'])->middleware('permission:attendance.view');
        Route::middleware('permission:attendance.manage')->group(function (): void {
            Route::patch('/attendances/{attendance}', [AttendanceController::class, 'update']);
            Route::post('/attendances/manual', [AttendanceController::class, 'storeManual']);
        });
        Route::get('/attendances/{attendance}/audits', [AuditController::class, 'forAttendance'])->middleware('permission:audit.view');

        Route::middleware('permission:leave.view')->group(function (): void {
            Route::get('/leaves', [LeaveController::class, 'index']);
            Route::get('/leaves/upcoming/{staff}', [LeaveController::class, 'upcoming']);
            Route::get('/leaves/{leave}', [LeaveController::class, 'show']);
        });
        Route::middleware('permission:leave.manage')->group(function (): void {
            Route::post('/leaves', [LeaveController::class, 'store']);
            Route::put('/leaves/{leave}', [LeaveController::class, 'update']);
            Route::delete('/leaves/{leave}', [LeaveController::class, 'destroy']);
        });

        Route::middleware('permission:holiday.view')->group(function (): void {
            Route::get('/public-holidays', [PublicHolidayController::class, 'index']);
            Route::get('/public-holidays/upcoming', [PublicHolidayController::class, 'upcoming']);
            Route::get('/public-holidays/{publicHoliday}', [PublicHolidayController::class, 'show']);
        });
        Route::middleware('permission:holiday.manage')->group(function (): void {
            Route::post('/public-holidays', [PublicHolidayController::class, 'store']);
            Route::put('/public-holidays/{publicHoliday}', [PublicHolidayController::class, 'update']);
            Route::delete('/public-holidays/{publicHoliday}', [PublicHolidayController::class, 'destroy']);
        });

        Route::get('/settings', [SettingsController::class, 'show'])->middleware('permission:settings.view');
        Route::put('/settings', [SettingsController::class, 'update'])->middleware('permission:settings.manage');

        Route::get('/subscription/status', [SubscriptionController::class, 'status']);
        Route::post('/subscription/initialize', [SubscriptionController::class, 'initialize'])->middleware('permission:settings.manage');
        Route::post('/subscription/verify', [SubscriptionController::class, 'verify'])->middleware('permission:settings.manage');

        Route::middleware('permission:organization.view')->group(function (): void {
            Route::get('/lookups/departments', [LookupController::class, 'departments']);
            Route::get('/lookups/staff', [LookupController::class, 'staffOptions']);
            Route::get('/lookups/branches', [LookupController::class, 'branches']);
            Route::get('/lookups/companies', [LookupController::class, 'companies']);
        });
        Route::middleware('permission:organization.manage')->group(function (): void {
            Route::put('/lookups/departments', [LookupController::class, 'updateDepartments']);
            Route::put('/lookups/branches', [LookupController::class, 'updateBranches']);
            Route::put('/lookups/companies', [LookupController::class, 'updateCompanies']);
        });

        Route::middleware(['permission:report.view', 'subscription.gate'])->group(function (): void {
            Route::get('/reports', [ReportController::class, 'index']);
            Route::post('/reports/export', [ReportController::class, 'export']);
            Route::get('/reports/export/pdf', [ReportController::class, 'exportPdf']);
            Route::get('/reports/compliance', [ReportController::class, 'compliance']);
            Route::get('/reports/comparisons', [ReportController::class, 'comparisons']);
        });

        // Audit visibility is an operational and accountability control, not
        // a paid-reporting feature. HR must retain access and the super
        // administrator must be able to correct/delete an individual record
        // even while a subscription payment is being resolved.
        Route::get('/audits', [AuditController::class, 'index'])->middleware('permission:audit.view');
        Route::get('/activity-logs', [AuditController::class, 'activityIndex'])->middleware('permission:audit.activity.view');
        Route::delete('/audits/{attendanceAudit}', [AuditController::class, 'destroyAttendanceAudit'])
            ->middleware('permission:audit.view');
        Route::delete('/activity-logs/{activityLog}', [AuditController::class, 'destroyActivityLog'])
            ->middleware('permission:audit.activity.view');

        Route::middleware('permission:users.manage')->group(function (): void {
            Route::get('/users', [UserController::class, 'index']);
            Route::post('/users', [UserController::class, 'store']);
            Route::put('/users/{user}', [UserController::class, 'update']);
            Route::post('/users/{user}/reset-password', [UserController::class, 'resetPassword']);
            Route::post('/users/{user}/revoke-sessions', [UserController::class, 'revokeSessions']);
            Route::get('/auth-events', [UserController::class, 'authEvents']);
        });

        Route::middleware('permission:devices.manage')->group(function (): void {
            Route::get('/devices', [KioskDeviceController::class, 'index']);
            Route::get('/devices/{device}/events', [KioskDeviceController::class, 'events']);
            Route::get('/devices/{device}/recoveries', [KioskDeviceController::class, 'recoveryRequests']);
            Route::post('/devices/{device}/recoveries/{recovery}/approve', [KioskDeviceController::class, 'approveRecovery']);
            Route::put('/devices/{device}', [KioskDeviceController::class, 'update']);
            Route::post('/devices/{device}/disable', [KioskDeviceController::class, 'disable']);
            Route::post('/devices/{device}/enable', [KioskDeviceController::class, 'enable']);
            Route::post('/devices/{device}/re-pair', [KioskDeviceController::class, 'resetPairing']);
        });
    });
});
