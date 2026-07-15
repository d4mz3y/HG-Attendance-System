<?php

namespace App\Services;

use App\Models\KioskScanQueue;
use App\Models\Staff;
use Carbon\Carbon;

class OfflineSyncService
{
    public function queueScan(string $code, ?string $deviceId = null, ?array $payload = null): KioskScanQueue
    {
        return KioskScanQueue::query()->create([
            'staff_id_code' => $code,
            'action' => $payload['action'] ?? null,
            'device_id' => $deviceId,
            'payload' => $payload,
            'status' => 'pending',
        ]);
    }

    public function syncPending(): int
    {
        $pending = KioskScanQueue::query()->where('status', 'pending')->get();
        $synced = 0;

        foreach ($pending as $item) {
            $staff = Staff::query()->where('staff_id', $item->staff_id_code)->first();
            if (! $staff || ! $staff->isActive()) {
                $item->update(['status' => 'failed']);
                continue;
            }

            try {
                $result = app(BiometricService::class)->handlePunch($item->staff_id_code, $item->device_id ?? 'offline-queue', $item->payload);
                if ($result['ok']) {
                    $item->update(['status' => 'synced', 'synced_at' => Carbon::now()]);
                    $synced++;
                } else {
                    $item->update(['status' => 'failed']);
                }
            } catch (\Throwable $e) {
                $item->update(['status' => 'failed']);
            }
        }

        return $synced;
    }

    public function pendingCount(): int
    {
        return KioskScanQueue::query()->where('status', 'pending')->count();
    }
}
