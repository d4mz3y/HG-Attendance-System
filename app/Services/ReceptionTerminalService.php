<?php

namespace App\Services;

use App\Models\KioskDevice;
use App\Models\KioskRecoveryRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class ReceptionTerminalService
{
    public const ROLE = 'reception';

    private const UNPAIRED_TOKEN_HASH = '0000000000000000000000000000000000000000000000000000000000000000';

    public function __construct(private readonly DeviceTokenService $tokens) {}

    /**
     * Return the one reception terminal, creating an unpaired placeholder
     * when IT visits Scan devices before the reception browser is opened.
     */
    public function reception(): KioskDevice
    {
        $this->ensurePairingSchema();

        return DB::transaction(function (): KioskDevice {
            $device = KioskDevice::query()
                ->where('terminal_role', self::ROLE)
                ->lockForUpdate()
                ->first();

            return $device ?? $this->newReceptionTerminal();
        }, 3);
    }

    /**
     * Pair the fixed browser without displaying or issuing a human-entered
     * token. IT must first create the placeholder in Scan devices and pin it
     * to the one reception IP; an arbitrary LAN visitor can never claim it.
     * The browser creates its own high-entropy secret and proves it on later
     * starts; only its hash is persisted by the server.
     */
    public function pair(Request $request, string $secret): KioskDevice
    {
        $this->ensurePairingSchema();
        abort_unless($this->tokens->globalIpIsAllowed($request->ip()), 403, 'Scanning is not allowed from this network.');

        return DB::transaction(function () use ($request, $secret): KioskDevice {
            $device = KioskDevice::query()
                ->where('terminal_role', self::ROLE)
                ->lockForUpdate()
                ->first();

            abort_if($device === null, 409, 'IT must configure the reception scanner IP address before this browser can pair.');

            abort_if(! $device->is_active || $device->revoked_at !== null, 423, 'The reception scanner is disabled by IT.');

            abort_if(
                $device->allowed_ips === null || trim($device->allowed_ips) === '',
                409,
                'IT must set the exact reception scanner IP address before this browser can pair.'
            );

            abort_unless(
                $this->tokens->receptionIpMatches($request->ip(), $device->allowed_ips),
                403,
                'This browser is not on the approved reception computer IP address.'
            );

            $secretHash = hash('sha256', $secret);
            if ($device->token_hash !== self::UNPAIRED_TOKEN_HASH && ! hash_equals($device->token_hash, $secretHash)) {
                abort(409, 'This reception scanner is already paired. Ask IT to re-pair the approved reception browser.');
            }

            if ($device->token_hash === self::UNPAIRED_TOKEN_HASH) {
                $device->forceFill([
                    'token_hash' => $secretHash,
                    'token_last_four' => substr($secret, -4),
                    'paired_at' => now(),
                    'is_active' => true,
                    'revoked_at' => null,
                ])->save();
            }

            $this->markSeen($device, $request);

            return $device->fresh();
        }, 3);
    }

    public function disable(KioskDevice $device): KioskDevice
    {
        $this->ensurePairingSchema();

        return DB::transaction(function () use ($device): KioskDevice {
            $locked = $this->lockedReception($device);
            $locked->forceFill(['is_active' => false])->save();

            return $locked->fresh();
        }, 3);
    }

    public function enable(KioskDevice $device): KioskDevice
    {
        $this->ensurePairingSchema();

        return DB::transaction(function () use ($device): KioskDevice {
            $locked = $this->lockedReception($device);
            $locked->forceFill(['is_active' => true, 'revoked_at' => null])->save();

            return $locked->fresh();
        }, 3);
    }

    /**
     * Forget the browser credential only after IT explicitly requests it.
     * The next visit to /scan from the approved reception IP silently pairs a
     * replacement browser. Pending recovery evidence must be dealt with
     * first, because it is authenticated by the old browser secret.
     */
    public function resetPairing(KioskDevice $device): KioskDevice
    {
        $this->ensurePairingSchema();

        return DB::transaction(function () use ($device): KioskDevice {
            $locked = $this->lockedReception($device);

            abort_if(
                ! $locked->is_active || $locked->revoked_at !== null,
                409,
                'Enable the reception scanner before preparing a replacement browser.'
            );

            abort_if(
                KioskRecoveryRequest::query()
                    ->where('kiosk_device_id', $locked->id)
                    ->whereIn('status', ['pending', 'approved', 'expired'])
                    ->exists(),
                409,
                'Resolve the pending recovery requests before re-pairing this browser.'
            );

            $locked->forceFill([
                'token_hash' => self::UNPAIRED_TOKEN_HASH,
                'token_last_four' => '----',
                'paired_at' => null,
            ])->save();

            return $locked->fresh();
        }, 3);
    }

    public function assertReception(KioskDevice $device): KioskDevice
    {
        $this->ensurePairingSchema();
        abort_unless($device->terminal_role === self::ROLE, 404);

        return $device;
    }

    private function lockedReception(KioskDevice $device): KioskDevice
    {
        $locked = KioskDevice::query()->whereKey($device->id)->lockForUpdate()->firstOrFail();
        $this->assertReception($locked);

        return $locked;
    }

    private function newReceptionTerminal(?string $allowedIp = null): KioskDevice
    {
        return KioskDevice::query()->create([
            'identifier' => (string) Str::uuid(),
            'name' => 'Reception scanner',
            'type' => 'kiosk',
            'terminal_role' => self::ROLE,
            'token_hash' => self::UNPAIRED_TOKEN_HASH,
            'token_last_four' => '----',
            'abilities' => ['scan'],
            'allowed_ips' => $allowedIp,
            'is_active' => true,
        ]);
    }

    /**
     * This feature was introduced after the first kiosk-device migration.
     * Never turn a missed deploy migration into a generic 500 error or let
     * the Devices screen spin forever: pairing is safely unavailable until
     * the office server's forward-only database update has completed.
     */
    private function ensurePairingSchema(): void
    {
        abort_unless(
            Schema::hasColumns('kiosk_devices', ['terminal_role', 'paired_at']),
            503,
            'The reception scanner database update is still pending. IT must update the office server, then try again.'
        );
    }

    private function markSeen(KioskDevice $device, Request $request): void
    {
        if ($device->last_seen_at === null || $device->last_seen_at->lt(now()->subMinutes(2)) || $device->last_ip !== $request->ip()) {
            $device->forceFill([
                'last_seen_at' => now(),
                'last_ip' => $request->ip(),
            ])->saveQuietly();
        }
    }
}
