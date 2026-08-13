<?php

namespace App\Models;

use App\Services\ReceptionTerminalService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class KioskDevice extends Model
{
    protected $fillable = [
        'identifier',
        'name',
        'type',
        'terminal_role',
        'token_hash',
        'token_last_four',
        'abilities',
        'allowed_ips',
        'is_active',
        'last_sequence',
        'last_event_at',
        'last_event_at_raw',
        'paired_at',
        'last_seen_at',
        'last_ip',
        'revoked_at',
        'created_by',
    ];

    protected $hidden = [
        'token_hash',
        'token_last_four',
    ];

    protected function casts(): array
    {
        return [
            'abilities' => 'array',
            'is_active' => 'boolean',
            'last_sequence' => 'integer',
            'last_event_at' => 'datetime',
            'paired_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function events(): HasMany
    {
        return $this->hasMany(KioskScanQueue::class);
    }

    public function recoveryRequests(): HasMany
    {
        return $this->hasMany(KioskRecoveryRequest::class);
    }

    public function can(string $ability): bool
    {
        return in_array($ability, $this->abilities ?? [], true);
    }

    public function isReceptionTerminal(): bool
    {
        return $this->terminal_role === ReceptionTerminalService::ROLE;
    }

    public function isPaired(): bool
    {
        return $this->token_hash !== str_repeat('0', 64) && $this->paired_at !== null;
    }
}
