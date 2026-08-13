<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StaffScheduleVersion extends Model
{
    protected $fillable = [
        'staff_id',
        'effective_from',
        'schedule',
        'changed_by',
        'reason',
    ];

    protected function casts(): array
    {
        return [
            'effective_from' => 'date',
            'schedule' => 'array',
        ];
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class);
    }

    public function changer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
