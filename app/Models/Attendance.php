<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Attendance extends Model
{
    protected $fillable = [
        'staff_id',
        'date',
        'session_number',
        'open_session',
        'clock_in',
        'clock_out',
        'total_hours',
        'is_late',
        'late_minutes',
        'overtime_minutes',
        'break_minutes',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'session_number' => 'integer',
            'open_session' => 'boolean',
            'clock_in' => 'datetime',
            'clock_out' => 'datetime',
            'is_late' => 'boolean',
            'total_hours' => 'decimal:2',
        ];
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'staff_id');
    }

    protected static function booted(): void
    {
        static::saving(function (Attendance $attendance): void {
            // A nullable marker gives us a portable database constraint that
            // permits many closed sessions (NULL) but only one open session
            // (TRUE) for each member of staff.
            $attendance->open_session = $attendance->clock_out === null ? true : null;
        });
    }

    public function toArray(): array
    {
        $array = parent::toArray();
        if ($this->date instanceof \DateTimeInterface) {
            $array['date'] = $this->date->format('Y-m-d');
        }

        return $array;
    }
}
