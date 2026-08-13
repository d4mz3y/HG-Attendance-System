<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KioskRecoveryRequest extends Model
{
    protected $fillable = [
        'request_uuid',
        'kiosk_device_id',
        'event_set_hash',
        'server_sequence',
        'requested_events',
        'status',
        'reviewed_by',
        'review_reason',
        'reviewed_at',
        'approved_until',
        'consumed_at',
    ];

    protected function casts(): array
    {
        return [
            'server_sequence' => 'integer',
            'requested_events' => 'array',
            'reviewed_at' => 'datetime',
            'approved_until' => 'datetime',
            'consumed_at' => 'datetime',
        ];
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(KioskDevice::class, 'kiosk_device_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
