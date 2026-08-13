<?php

namespace App\Models;

use App\Auth\Permission;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, Notifiable;

    protected $fillable = [
        'username',
        'password',
        'role',
        'is_active',
        'must_change_password',
        'password_changed_at',
        'last_login_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'is_active' => 'boolean',
            'must_change_password' => 'boolean',
            'password_changed_at' => 'datetime',
            'last_login_at' => 'datetime',
        ];
    }

    /** @return list<string> */
    public function permissions(): array
    {
        return Permission::forRole($this->role);
    }

    public function hasPermission(string $permission): bool
    {
        return Permission::roleHas($this->role, $permission);
    }

    public function isSuperAdmin(): bool
    {
        return $this->role === 'super_admin';
    }

    public function canChangeOwnPassword(): bool
    {
        return $this->hasPermission(Permission::PASSWORD_CHANGE_SELF);
    }

    public function isAdmin(): bool
    {
        return $this->hasPermission(Permission::DASHBOARD_VIEW);
    }

    public function canManageStaff(): bool
    {
        return $this->hasPermission(Permission::STAFF_MANAGE);
    }

    public function canManageAttendance(): bool
    {
        return $this->hasPermission(Permission::ATTENDANCE_MANAGE);
    }

    public function canManageLeave(): bool
    {
        return $this->hasPermission(Permission::LEAVE_MANAGE);
    }

    public function canManageSchedules(): bool
    {
        return $this->hasPermission(Permission::SCHEDULE_MANAGE);
    }

    public function canManageSystem(): bool
    {
        return $this->hasPermission(Permission::SETTINGS_MANAGE);
    }
}
