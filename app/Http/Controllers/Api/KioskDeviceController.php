<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\KioskDevice;
use App\Models\KioskRecoveryRequest;
use App\Models\KioskScanQueue;
use App\Services\AuthEventService;
use App\Services\DeviceTokenService;
use App\Services\KioskRecoveryService;
use App\Services\ReceptionTerminalService;
use Illuminate\Http\Request;

class KioskDeviceController extends Controller
{
    public function __construct(
        private readonly DeviceTokenService $tokens,
        private readonly AuthEventService $events,
        private readonly KioskRecoveryService $recoveries,
        private readonly ReceptionTerminalService $reception,
    ) {}

    /**
     * The company has one fixed reception scanner. Opening Scan devices also
     * creates its unpaired placeholder so IT can approve the exact IP
     * before the browser is ever allowed to self-pair.
     */
    public function index()
    {
        $device = $this->reception->reception()->load('creator:id,username');

        return response()->json(['data' => [$this->devicePayload($device)]]);
    }

    public function update(Request $request, KioskDevice $device)
    {
        $this->reception->assertReception($device);
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            // Pairing is intentionally impossible with an empty device rule;
            // this prevents a random approved-LAN visitor from claiming it.
            'allowed_ips' => ['sometimes', 'required', 'string', 'max:2048', $this->validIpRules(...)],
        ]);

        $before = $device->only(['name', 'allowed_ips']);
        $device->update($data);
        $fresh = $device->fresh();
        $this->events->record($request, 'reception_scanner_updated', $request->user(), metadata: [
            'device_id' => $fresh->id,
            'before' => $before,
            'after' => $fresh->only(['name', 'allowed_ips']),
        ]);

        return response()->json(['device' => $this->devicePayload($fresh)]);
    }

    public function disable(Request $request, KioskDevice $device)
    {
        $device = $this->reception->disable($device);
        $this->events->record($request, 'reception_scanner_disabled', $request->user(), metadata: [
            'device_id' => $device->id,
            'device_name' => $device->name,
        ]);

        return response()->json(['device' => $this->devicePayload($device)]);
    }

    public function enable(Request $request, KioskDevice $device)
    {
        $device = $this->reception->enable($device);
        $this->events->record($request, 'reception_scanner_enabled', $request->user(), metadata: [
            'device_id' => $device->id,
            'device_name' => $device->name,
        ]);

        return response()->json(['device' => $this->devicePayload($device)]);
    }

    public function resetPairing(Request $request, KioskDevice $device)
    {
        $request->validate([
            'confirm_queue_resolved' => ['accepted'],
        ]);
        $device = $this->reception->resetPairing($device);
        $this->events->record($request, 'reception_scanner_repair_requested', $request->user(), metadata: [
            'device_id' => $device->id,
            'device_name' => $device->name,
        ]);

        return response()->json([
            'device' => $this->devicePayload($device),
            'message' => 'The reception browser has been cleared for re-pairing. Open /scan on the approved reception computer to pair it automatically.',
        ]);
    }

    public function events(KioskDevice $device, Request $request)
    {
        $this->reception->assertReception($device);

        return response()->json(
            KioskScanQueue::query()
                ->where('kiosk_device_id', $device->id)
                ->latest('occurred_at')
                ->select([
                    'id',
                    'event_uuid',
                    'kiosk_device_id',
                    'sequence',
                    'staff_id_code',
                    'occurred_at',
                    'occurred_at_raw',
                    'status',
                    'error_code',
                    'error_message',
                    'processed_at',
                ])
                ->paginate(min(100, max(1, $request->integer('per_page', 50))))
        );
    }

    public function recoveryRequests(KioskDevice $device, Request $request)
    {
        $this->reception->assertReception($device);

        return response()->json(
            KioskRecoveryRequest::query()
                ->where('kiosk_device_id', $device->id)
                ->with('reviewer:id,username')
                ->latest()
                ->paginate(min(100, max(1, $request->integer('per_page', 50))))
        );
    }

    public function approveRecovery(Request $request, KioskDevice $device, KioskRecoveryRequest $recovery)
    {
        $this->reception->assertReception($device);
        abort_unless($recovery->kiosk_device_id === $device->id, 404);
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:10', 'max:500'],
        ]);
        $recovery = $this->recoveries->approve($device, $recovery, $request->user(), $data['reason']);

        $this->events->record($request, 'device_recovery_approved', $request->user(), metadata: [
            'device_id' => $device->id,
            'recovery_request_id' => $recovery->request_uuid,
            'event_ids' => collect($recovery->requested_events)->pluck('event_id')->all(),
            'reason' => $data['reason'],
        ]);

        return response()->json(['ok' => true, 'recovery' => $recovery->load('reviewer:id,username')]);
    }

    /** @return array<string, mixed> */
    private function devicePayload(KioskDevice $device): array
    {
        return [
            'id' => $device->id,
            'identifier' => $device->identifier,
            'name' => $device->name,
            'type' => $device->type,
            'terminal_role' => $device->terminal_role,
            'allowed_ips' => $device->allowed_ips,
            'is_active' => $device->is_active,
            'paired' => $device->isPaired(),
            'paired_at' => $device->paired_at,
            'last_sequence' => $device->last_sequence,
            'last_seen_at' => $device->last_seen_at,
            'last_ip' => $device->last_ip,
            'created_at' => $device->created_at,
        ];
    }

    private function validIpRules(string $attribute, mixed $value, \Closure $fail): void
    {
        $ip = trim((string) $value);
        if (! $this->tokens->receptionIpMatches($ip, $ip)) {
            $fail("The {$attribute} field must contain one exact IPv4 or IPv6 reception-computer address, not a CIDR range or list.");

            return;
        }
    }
}
