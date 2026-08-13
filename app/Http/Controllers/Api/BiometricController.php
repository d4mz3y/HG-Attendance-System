<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\KioskDevice;
use App\Services\OfflineSyncService;
use Illuminate\Http\Request;

class BiometricController extends Controller
{
    public function __construct(private readonly OfflineSyncService $offlineSync) {}

    public function config(Request $request)
    {
        /** @var KioskDevice $device */
        $device = $request->attributes->get('kiosk_device');

        return response()->json([
            'server_time' => now()->utc()->toIso8601String(),
            'device_id' => $device->identifier,
            'next_sequence' => $device->last_sequence + 1,
        ]);
    }

    public function punch(Request $request)
    {
        $data = $request->validate([
            'event_id' => ['required', 'uuid'],
            'identifier' => ['required', 'string', 'max:128'],
            'occurred_at' => ['required', 'string', 'max:64'],
            'sequence' => ['required', 'integer', 'min:1'],
            'signature' => ['required', 'string', 'regex:/^[a-fA-F0-9]{64}$/'],
        ]);

        /** @var KioskDevice $device */
        $device = $request->attributes->get('kiosk_device');
        $results = $this->offlineSync->processBatch(
            $device,
            (string) $request->attributes->get('device_token_secret'),
            [[
                'event_id' => $data['event_id'],
                'code' => $data['identifier'],
                'occurred_at' => $data['occurred_at'],
                'sequence' => $data['sequence'],
                'signature' => $data['signature'],
            ]],
        );

        $result = $results[0];

        return response()->json($result, $result['accepted'] ? 200 : ($result['retryable'] ? 409 : 422));
    }
}
