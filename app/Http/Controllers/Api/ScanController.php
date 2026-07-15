<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\OfflineSyncService;
use App\Services\ScanService;
use Illuminate\Http\Request;

class ScanController extends Controller
{
    public function __construct(
        protected ScanService $scanService,
        protected OfflineSyncService $offlineSync
    ) {}

    public function store(Request $request)
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:128'],
            'device_id' => ['nullable', 'string', 'max:128'],
            'offline' => ['nullable', 'boolean'],
        ]);

        if (! empty($data['offline'])) {
            $item = $this->offlineSync->queueScan($data['code'], $data['device_id'] ?? null, [
                'action' => 'queue',
                'raw_code' => $data['code'],
            ]);

            return response()->json(['ok' => true, 'queued' => true, 'queue_id' => $item->id]);
        }

        $result = $this->scanService->handleScan($data['code']);

        return response()->json($result, $result['ok'] ? 200 : 422);
    }

    public function sync(Request $request)
    {
        $synced = $this->offlineSync->syncPending();

        return response()->json(['ok' => true, 'synced' => $synced]);
    }

    public function pending(Request $request)
    {
        return response()->json(['pending' => $this->offlineSync->pendingCount()]);
    }
}
