<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\KioskDevice;
use App\Models\KioskScanQueue;
use App\Models\Setting;
use App\Services\KioskRecoveryService;
use App\Services\OfflineSyncService;
use App\Services\ReceptionTerminalService;
use Illuminate\Http\Request;

class ScanController extends Controller
{
    public function __construct(
        private readonly OfflineSyncService $offlineSync,
        private readonly KioskRecoveryService $recoveries,
        private readonly ReceptionTerminalService $reception,
    ) {}

    public function config(Request $request)
    {
        /** @var KioskDevice|null $device */
        $device = $request->attributes->get('kiosk_device');

        return response()->json([
            'server_time' => now()->utc()->toIso8601String(),
            'device' => $device ? [
                'id' => $device->identifier,
                'name' => $device->name,
                'type' => $device->type,
                'next_sequence' => $device->last_sequence + 1,
            ] : null,
            'offline_enabled' => $device !== null,
            'offline_max_age_hours' => (int) Setting::getValue('offline_max_age_hours', '72'),
            'scan_debounce_seconds' => (int) Setting::getValue('scan_debounce_seconds', '2'),
            'branch_label' => Setting::getValue('branch_label', 'Headquarters'),
            'dark_mode_default' => Setting::getValue('dark_mode_default', '0') === '1',
        ]);
    }

    public function pairReception(Request $request)
    {
        $data = $request->validate([
            'secret' => ['required', 'string', 'size:64', 'regex:/^[a-fA-F0-9]{64}$/'],
        ]);

        $device = $this->reception->pair($request, strtolower($data['secret']));

        return response()->json([
            'device' => [
                'id' => $device->identifier,
                'name' => $device->name,
                'type' => $device->type,
                'next_sequence' => $device->last_sequence + 1,
            ],
            'server_time' => now()->utc()->toIso8601String(),
        ])->header('Cache-Control', 'no-store');
    }

    public function store(Request $request)
    {
        /** @var KioskDevice $device */
        $device = $request->attributes->get('kiosk_device');
        abort_unless($device instanceof KioskDevice, 401);

        $event = $request->validate($this->eventRules());
        $results = $this->offlineSync->processBatch(
            $device,
            (string) $request->attributes->get('device_token_secret'),
            [$event],
        );

        $result = $results[0];

        return response()->json($result, $result['accepted'] ? 200 : ($result['retryable'] ? 409 : 422));
    }

    public function sync(Request $request)
    {
        $data = $request->validate([
            'events' => ['required', 'array', 'min:1', 'max:100'],
            'events.*.event_id' => ['required', 'uuid'],
            'events.*.code' => ['required', 'string', 'max:128'],
            'events.*.occurred_at' => ['required', 'string', 'max:64'],
            'events.*.sequence' => ['required', 'integer', 'min:1'],
            'events.*.signature' => ['required', 'string', 'regex:/^[a-fA-F0-9]{64}$/'],
        ]);

        /** @var KioskDevice $device */
        $device = $request->attributes->get('kiosk_device');
        $results = $this->offlineSync->processBatch(
            $device,
            (string) $request->attributes->get('device_token_secret'),
            $data['events'],
        );

        return response()->json([
            'ok' => collect($results)->every(fn (array $result): bool => (bool) $result['accepted']),
            'results' => $results,
            'next_sequence' => $device->fresh()->last_sequence + 1,
        ]);
    }

    public function history(Request $request)
    {
        /** @var KioskDevice $device */
        $device = $request->attributes->get('kiosk_device');
        $events = KioskScanQueue::query()
            ->where('kiosk_device_id', $device->id)
            ->latest('occurred_at')
            ->limit(50)
            ->get([
                'event_uuid',
                'sequence',
                'staff_id_code',
                'occurred_at',
                'occurred_at_raw',
                'status',
                'error_code',
                'error_message',
                'processed_at',
            ]);

        return response()->json(['events' => $events]);
    }

    public function recover(Request $request)
    {
        $data = $request->validate([
            'blocked_events' => ['required', 'array', 'min:1', 'max:100'],
            'blocked_events.*.event_id' => ['required', 'uuid', 'distinct'],
            'blocked_events.*.sequence' => ['required', 'integer', 'min:1', 'distinct'],
            'blocked_events.*.code' => ['required', 'string', 'max:128'],
            'blocked_events.*.occurred_at' => ['required', 'string', 'max:64'],
            'blocked_events.*.signature' => ['required', 'string', 'regex:/^[a-fA-F0-9]{64}$/'],
            'blocked_events.*.error' => ['nullable', 'string', 'max:64'],
            'blocked_events.*.message' => ['nullable', 'string', 'max:1000'],
        ]);

        /** @var KioskDevice $device */
        $device = $request->attributes->get('kiosk_device');
        $result = $this->recoveries->submit(
            $device,
            (string) $request->attributes->get('device_token_secret'),
            $data['blocked_events'],
        );
        $recovery = $result['request'];

        if (! $result['recovered']) {
            return response()->json([
                'status' => $recovery->status,
                'request_id' => $recovery->request_uuid,
                'message' => $recovery->status === 'expired'
                    ? 'The IT approval expired. Ask IT to review and approve this request again.'
                    : 'Recovery was requested. IT must review the exact blocked events before this kiosk can continue.',
            ], 202)->header('Cache-Control', 'no-store');
        }

        return response()->json([
            'status' => 'approved',
            'request_id' => $recovery->request_uuid,
            'acknowledged_event_ids' => $result['acknowledged_event_ids'],
            'next_sequence' => $result['next_sequence'],
        ])->header('Cache-Control', 'no-store');
    }

    /** @return array<string, list<string>> */
    private function eventRules(): array
    {
        return [
            'event_id' => ['required', 'uuid'],
            'code' => ['required', 'string', 'max:128'],
            'occurred_at' => ['required', 'string', 'max:64'],
            'sequence' => ['required', 'integer', 'min:1'],
            'signature' => ['required', 'string', 'regex:/^[a-fA-F0-9]{64}$/'],
        ];
    }
}
