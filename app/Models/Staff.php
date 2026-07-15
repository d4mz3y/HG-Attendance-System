<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

class Staff extends Model
{
    protected $table = 'staff';

    protected $appends = ['photo_url'];

    protected $fillable = [
        'staff_id',
        'full_name',
        'department',
        'job_title',
        'branch',
        'photo_path',
        'employment_status',
    ];

    public function attendances(): HasMany
    {
        return $this->hasMany(Attendance::class, 'staff_id');
    }

    public function isActive(): bool
    {
        return $this->employment_status === 'Active';
    }

    public function getPhotoUrlAttribute(): ?string
    {
        return $this->photo_path
            ? Storage::disk('public')->url($this->photo_path)
            : null;
    }
}
