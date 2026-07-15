<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class KioskScanQueue extends Model
{
    protected $fillable = [
        'staff_id_code',
        'action',
        'device_id',
        'payload',
        'status',
        'synced_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'synced_at' => 'datetime',
        ];
    }
}
