<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttendanceAudit extends Model
{
    protected $fillable = [
        'attendance_id',
        'changed_fields',
        'old_values',
        'new_values',
        'changed_by',
        'ip_address',
        'reason',
    ];

    protected function casts(): array
    {
        return [
            'changed_fields' => 'array',
            'old_values' => 'array',
            'new_values' => 'array',
        ];
    }

    public function attendance(): BelongsTo
    {
        return $this->belongsTo(Attendance::class, 'attendance_id');
    }

    public function changer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
