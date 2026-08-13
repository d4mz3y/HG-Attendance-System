<?php

namespace App\Services;

use App\Models\KioskDevice;
use App\Models\KioskScanQueue;
use App\Models\Setting;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

class OfflineSyncService
{
    public function __construct(
        private readonly ScanService $scans,
        private readonly DeviceTokenService $tokens,
    ) {}

    /**
     * @param  list<array{event_id: string, code: string, occurred_at: string, sequence: int, signature: string}>  $events
     * @return list<array<string, mixed>>
     */
    public function processBatch(KioskDevice $device, string $secret, array $events): array
    {
        usort($events, static fn (array $left, array $right): int => $left['sequence'] <=> $right['sequence']);

        $results = [];
        foreach ($events as $event) {
            try {
                $results[] = $this->processEvent($device, $secret, $event);
            } catch (Throwable $exception) {
                report($exception);
                $results[] = [
                    'event_id' => $event['event_id'],
                    'sequence' => $event['sequence'],
                    'accepted' => false,
                    'retryable' => true,
                    'error' => 'processing_unavailable',
                    'message' => 'The server could not safely process this event. It remains queued on the device.',
                ];
                break;
            }
        }

        return $results;
    }

    /**
     * @param  array{event_id: string, code: string, occurred_at: string, sequence: int, signature: string}  $event
     * @return array<string, mixed>
     */
    private function processEvent(KioskDevice $device, string $secret, array $event): array
    {
        $canonical = $this->tokens->canonicalEvent(
            $event['event_id'],
            trim($event['code']),
            $event['occurred_at'],
            $event['sequence'],
        );
        $payloadHash = hash('sha256', $canonical);
        $expectedSignature = $this->tokens->expectedSignature(
            $secret,
            $event['event_id'],
            trim($event['code']),
            $event['occurred_at'],
            $event['sequence'],
        );

        if (! hash_equals($expectedSignature, strtolower($event['signature']))) {
            return $this->rejection($event, 'invalid_signature', 'The event signature is invalid.', false);
        }

        try {
            $occurredAt = CarbonImmutable::createFromFormat('!Y-m-d\TH:i:s.v\Z', $event['occurred_at'], 'UTC');
            if (! $occurredAt || $occurredAt->format('Y-m-d\TH:i:s.v\Z') !== $event['occurred_at']) {
                throw new \InvalidArgumentException('Timestamp is not canonical UTC ISO-8601.');
            }
        } catch (Throwable) {
            return $this->rejection($event, 'invalid_timestamp', 'The event timestamp is invalid.', false);
        }

        // The database columns are timezone-naive. Persist in the configured
        // application timezone so Eloquent reconstructs the same instant.
        $occurredAt = $occurredAt->setTimezone((string) config('app.timezone'));

        return DB::transaction(function () use ($device, $event, $occurredAt, $payloadHash): array {
            $lockedDevice = KioskDevice::query()->lockForUpdate()->findOrFail($device->id);

            if (! $lockedDevice->is_active || $lockedDevice->revoked_at !== null) {
                return $this->rejection($event, 'device_revoked', 'This device has been revoked.', false);
            }

            $existingById = KioskScanQueue::query()->where('event_uuid', $event['event_id'])->first();
            if ($existingById) {
                if ($existingById->kiosk_device_id !== $lockedDevice->id || ! hash_equals((string) $existingById->payload_hash, $payloadHash)) {
                    return $this->rejection($event, 'event_tampered', 'This event identifier was already used with different content.', false);
                }

                return $this->storedResult($existingById, true);
            }

            $maxAgeHours = max(1, min(168, (int) Setting::getValue('offline_max_age_hours', '72')));
            $futureTolerance = max(0, min(900, (int) Setting::getValue('scan_clock_skew_seconds', '300')));
            if ($occurredAt->isAfter(now()->addSeconds($futureTolerance))) {
                return $this->rejection($event, 'future_timestamp', 'The kiosk clock is too far ahead of the server clock.', false);
            }
            if ($occurredAt->isBefore(now()->subHours($maxAgeHours))) {
                return $this->rejection($event, 'event_too_old', "Offline events older than {$maxAgeHours} hours require manual review.", false);
            }
            if ($lockedDevice->created_at && $occurredAt->isBefore($lockedDevice->created_at->subSeconds($futureTolerance))) {
                return $this->rejection($event, 'before_device_provisioning', 'This event predates the device registration.', false);
            }

            $existingSequence = KioskScanQueue::query()
                ->where('kiosk_device_id', $lockedDevice->id)
                ->where('sequence', $event['sequence'])
                ->first();
            if ($existingSequence) {
                return $this->rejection($event, 'sequence_conflict', 'This device sequence was already used by another event.', false);
            }

            $expectedSequence = $lockedDevice->last_sequence + 1;
            if ($event['sequence'] !== $expectedSequence) {
                return [
                    ...$this->rejection($event, 'sequence_gap', "Expected device sequence {$expectedSequence}.", true),
                    'expected_sequence' => $expectedSequence,
                ];
            }

            $lastDeviceInstant = $lockedDevice->last_event_at_raw
                ? CarbonImmutable::createFromFormat('!Y-m-d\TH:i:s.v\Z', $lockedDevice->last_event_at_raw, 'UTC')
                : $lockedDevice->last_event_at?->utc();
            if ($lastDeviceInstant && $occurredAt->isBefore($lastDeviceInstant)) {
                return $this->rejection($event, 'out_of_order', 'This event occurred before the last accepted device event.', false);
            }

            $queue = KioskScanQueue::query()->create([
                'event_uuid' => $event['event_id'],
                'kiosk_device_id' => $lockedDevice->id,
                'sequence' => $event['sequence'],
                'staff_id_code' => trim($event['code']),
                'occurred_at' => $occurredAt,
                'occurred_at_raw' => $event['occurred_at'],
                'signature' => strtolower($event['signature']),
                'payload_hash' => $payloadHash,
                'status' => 'pending',
            ]);

            $scanResult = $this->scans->handleScan(trim($event['code']), $occurredAt);
            $successful = (bool) ($scanResult['ok'] ?? false);
            $queue->forceFill([
                'status' => $successful ? 'synced' : 'failed',
                'error_code' => $successful ? null : ($scanResult['error'] ?? 'scan_rejected'),
                'error_message' => $successful ? null : ($scanResult['message'] ?? 'The scan was rejected.'),
                'result' => $scanResult,
                'synced_at' => $successful ? now() : null,
                'processed_at' => now(),
            ])->save();

            $lockedDevice->forceFill([
                'last_sequence' => $event['sequence'],
                'last_event_at' => $occurredAt,
                'last_event_at_raw' => $event['occurred_at'],
            ])->save();

            return $this->storedResult($queue->fresh(), false);
        }, 3);
    }

    /** @param array{event_id: string, sequence: int} $event */
    private function rejection(array $event, string $error, string $message, bool $retryable): array
    {
        return [
            'event_id' => $event['event_id'],
            'sequence' => $event['sequence'],
            'accepted' => false,
            'retryable' => $retryable,
            'error' => $error,
            'message' => $message,
        ];
    }

    /** @return array<string, mixed> */
    private function storedResult(KioskScanQueue $event, bool $duplicate): array
    {
        return [
            'event_id' => $event->event_uuid,
            'sequence' => $event->sequence,
            'accepted' => true,
            'duplicate' => $duplicate,
            'status' => $event->status,
            'error' => $event->error_code,
            'message' => $event->error_message,
            'result' => $event->result,
            'processed_at' => $event->processed_at?->toIso8601String(),
        ];
    }
}
