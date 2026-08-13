<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DefaultScheduleVersion extends Model
{
    protected $fillable = [
        'effective_from',
        'shift_start',
        'shift_end',
        'default_work_days',
        'default_break_minutes',
        'changed_by',
        'reason',
    ];

    protected function casts(): array
    {
        return [
            'effective_from' => 'date',
            'default_work_days' => 'array',
            'default_break_minutes' => 'integer',
        ];
    }

    public function changer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
