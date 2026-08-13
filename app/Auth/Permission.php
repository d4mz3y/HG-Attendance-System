<?php

namespace App\Auth;

final class Permission
{
    public const DASHBOARD_VIEW = 'dashboard.view';

    public const STAFF_VIEW = 'staff.view';

    public const STAFF_MANAGE = 'staff.manage';

    public const STAFF_EXPORT = 'staff.export';

    public const SCHEDULE_VIEW = 'schedule.view';

    public const SCHEDULE_MANAGE = 'schedule.manage';

    public const ATTENDANCE_VIEW = 'attendance.view';

    public const ATTENDANCE_MANAGE = 'attendance.manage';

    public const LEAVE_VIEW = 'leave.view';

    public const LEAVE_MANAGE = 'leave.manage';

    public const HOLIDAY_VIEW = 'holiday.view';

    public const HOLIDAY_MANAGE = 'holiday.manage';

    public const REPORT_VIEW = 'report.view';

    public const AUDIT_VIEW = 'audit.view';

    /**
     * Technical request/activity records include implementation details such
     * as HTTP paths and payload metadata. HR only needs the plain attendance
     * correction history, while IT and the super administrator need this
     * operational troubleshooting view.
     */
    public const AUDIT_ACTIVITY_VIEW = 'audit.activity.view';

    public const ORGANIZATION_VIEW = 'organization.view';

    public const ORGANIZATION_MANAGE = 'organization.manage';

    public const SETTINGS_VIEW = 'settings.view';

    public const SETTINGS_MANAGE = 'settings.manage';

    public const USERS_MANAGE = 'users.manage';

    public const DEVICES_MANAGE = 'devices.manage';

    /**
     * Changing one's own portal password is deliberately reserved for the
     * super administrator. Other portal accounts are supported by IT through
     * the Portal users screen, so an absent HR/IT colleague never blocks the
     * reception terminal or a normal HR workday.
     */
    public const PASSWORD_CHANGE_SELF = 'password.change_self';

    public const SCAN_USE = 'scan.use';

    /** @var array<string, list<string>> */
    private const ROLE_PERMISSIONS = [
        'admin' => [
            self::DASHBOARD_VIEW,
            self::STAFF_VIEW,
            self::SCHEDULE_VIEW,
            self::ATTENDANCE_VIEW,
            self::LEAVE_VIEW,
            self::HOLIDAY_VIEW,
            self::REPORT_VIEW,
            self::ORGANIZATION_VIEW,
            self::SCAN_USE,
        ],
        'hr_assistant' => [
            self::DASHBOARD_VIEW,
            self::STAFF_VIEW,
            self::SCHEDULE_VIEW,
            self::ATTENDANCE_VIEW,
            self::LEAVE_VIEW,
            self::LEAVE_MANAGE,
            self::HOLIDAY_VIEW,
            self::REPORT_VIEW,
            self::AUDIT_VIEW,
            self::ORGANIZATION_VIEW,
            self::ORGANIZATION_MANAGE,
            self::SETTINGS_VIEW,
            self::SCAN_USE,
        ],
        'hr' => [
            self::DASHBOARD_VIEW,
            self::STAFF_VIEW,
            self::STAFF_MANAGE,
            self::STAFF_EXPORT,
            self::SCHEDULE_VIEW,
            self::SCHEDULE_MANAGE,
            self::ATTENDANCE_VIEW,
            self::ATTENDANCE_MANAGE,
            self::LEAVE_VIEW,
            self::LEAVE_MANAGE,
            self::HOLIDAY_VIEW,
            self::HOLIDAY_MANAGE,
            self::REPORT_VIEW,
            self::AUDIT_VIEW,
            self::ORGANIZATION_VIEW,
            self::ORGANIZATION_MANAGE,
            self::SETTINGS_VIEW,
            self::SCAN_USE,
        ],
        'it_manager' => [
            self::DASHBOARD_VIEW,
            self::STAFF_VIEW,
            self::STAFF_MANAGE,
            self::STAFF_EXPORT,
            self::SCHEDULE_VIEW,
            self::SCHEDULE_MANAGE,
            self::ATTENDANCE_VIEW,
            self::ATTENDANCE_MANAGE,
            self::LEAVE_VIEW,
            self::LEAVE_MANAGE,
            self::HOLIDAY_VIEW,
            self::HOLIDAY_MANAGE,
            self::REPORT_VIEW,
            self::AUDIT_VIEW,
            self::AUDIT_ACTIVITY_VIEW,
            self::ORGANIZATION_VIEW,
            self::ORGANIZATION_MANAGE,
            self::SETTINGS_VIEW,
            self::SETTINGS_MANAGE,
            self::USERS_MANAGE,
            self::DEVICES_MANAGE,
            self::SCAN_USE,
        ],
        'super_admin' => ['*'],
    ];

    /** @return list<string> */
    public static function forRole(string $role): array
    {
        return self::ROLE_PERMISSIONS[$role] ?? [];
    }

    public static function roleHas(string $role, string $permission): bool
    {
        $permissions = self::forRole($role);

        return in_array('*', $permissions, true) || in_array($permission, $permissions, true);
    }

    /** @return list<string> */
    public static function roles(): array
    {
        return ['hr_assistant', 'hr', 'it_manager', 'super_admin'];
    }
}
