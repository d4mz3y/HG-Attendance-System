<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KioskScanQueue extends Model
{
    protected $table = 'kiosk_scan_queue';

    protected $fillable = [
        'event_uuid',
        'kiosk_device_id',
        'sequence',
        'staff_id_code',
        'occurred_at',
        'occurred_at_raw',
        'signature',
        'payload_hash',
        'status',
        'error_code',
        'error_message',
        'result',
        'synced_at',
        'processed_at',
    ];

    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'occurred_at' => 'datetime',
            'result' => 'array',
            'synced_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(KioskDevice::class, 'kiosk_device_id');
    }
}
