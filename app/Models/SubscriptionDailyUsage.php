<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SubscriptionDailyUsage extends Model
{
    protected $fillable = ['usage_date', 'scan_count'];

    protected function casts(): array
    {
        return [
            'usage_date' => 'date',
            'scan_count' => 'integer',
        ];
    }
}
