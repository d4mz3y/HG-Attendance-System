<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HolidayOccurrenceSnapshot extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'date',
        'public_holiday_id',
        'source_date',
        'name',
        'description',
        'is_recurring',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'source_date' => 'date',
            'is_recurring' => 'boolean',
        ];
    }
}
