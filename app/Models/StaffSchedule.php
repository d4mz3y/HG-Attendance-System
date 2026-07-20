<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StaffSchedule extends Model
{
    protected $table = 'staff_schedules';

    protected $fillable = [
        'staff_id',
        'day_of_week',
        'shift_start',
        'shift_end',
        'break_minutes',
        'is_day_off',
        'works_on_public_holiday',
    ];

    protected function casts(): array
    {
        return [
            'shift_start' => 'datetime:H:i',
            'shift_end' => 'datetime:H:i',
            'is_day_off' => 'boolean',
            'works_on_public_holiday' => 'boolean',
        ];
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'staff_id');
    }
}
