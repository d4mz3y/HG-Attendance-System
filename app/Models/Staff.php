<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\URL;

class Staff extends Model
{
    protected $table = 'staff';

    protected $appends = ['photo_url'];

    protected $fillable = [
        'staff_id',
        'company',
        'full_name',
        'department',
        'job_title',
        'branch',
        'photo_path',
        'employment_status',
        'employment_start_date',
        'employment_end_date',
    ];

    protected function casts(): array
    {
        return [
            'employment_start_date' => 'date',
            'employment_end_date' => 'date',
        ];
    }

    public function attendances(): HasMany
    {
        return $this->hasMany(Attendance::class, 'staff_id');
    }

    public function employmentHistory(): HasMany
    {
        return $this->hasMany(StaffEmploymentHistory::class)->orderByDesc('effective_from');
    }

    public function scheduleVersions(): HasMany
    {
        return $this->hasMany(StaffScheduleVersion::class)->orderByDesc('effective_from');
    }

    public function assignmentHistory(): HasMany
    {
        return $this->hasMany(StaffAssignmentHistory::class)->orderByDesc('effective_from');
    }

    public function isActive(): bool
    {
        return $this->employment_status === 'Active';
    }

    public function getPhotoUrlAttribute(): ?string
    {
        return $this->photo_path
            ? URL::temporarySignedRoute('staff.photo', now()->addMinutes(10), ['staffId' => $this->getKey()])
            : null;
    }
}
