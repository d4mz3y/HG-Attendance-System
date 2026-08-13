<?php

namespace App\Services;

use App\Models\KioskDevice;
use App\Models\KioskRecoveryRequest;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class KioskRecoveryService
{
    public function __construct(private readonly DeviceTokenService $tokens) {}

    /**
     * @param  list<array{event_id: string, sequence: int, code: string, occurred_at: string, error?: string|null, message?: string|null}>  $events
     * @return array{request: KioskRecoveryRequest, recovered: bool, acknowledged_event_ids: list<string>, next_sequence: int}
     */
    public function submit(KioskDevice $device, string $secret, array $events): array
    {
        return DB::transaction(function () use ($device, $secret, $events): array {
            $lockedDevice = KioskDevice::query()->whereKey($device->id)->lockForUpdate()->firstOrFail();
            $this->assertActive($lockedDevice);

            $events = $this->normalizeEvents($events);
            $this->assertEventsAreAuthentic($secret, $events);
            $expectedSequence = $lockedDevice->last_sequence + 1;
            $this->validateRecoverableSequences($events, $expectedSequence);

            $eventSetHash = hash('sha256', json_encode($events, JSON_THROW_ON_ERROR));
            $recovery = KioskRecoveryRequest::query()
                ->where('kiosk_device_id', $lockedDevice->id)
                ->where('event_set_hash', $eventSetHash)
                ->lockForUpdate()
                ->first();

            if (! $recovery) {
                $recovery = KioskRecoveryRequest::query()->create([
                    'request_uuid' => (string) Str::uuid(),
                    'kiosk_device_id' => $lockedDevice->id,
                    'event_set_hash' => $eventSetHash,
                    'server_sequence' => $lockedDevice->last_sequence,
                    'requested_events' => $events,
                    'status' => 'pending',
                ]);
            }

            if ($recovery->status === 'approved' && $recovery->approved_until?->isPast()) {
                $recovery->forceFill(['status' => 'expired'])->save();
            }

            $recovered = in_array($recovery->status, ['approved', 'consumed'], true);
            if ($recovered && $recovery->status === 'approved') {
                $recovery->forceFill(['status' => 'consumed', 'consumed_at' => now()])->save();
            }

            return [
                'request' => $recovery->fresh(),
                'recovered' => $recovered,
                'acknowledged_event_ids' => $recovered ? array_column($events, 'event_id') : [],
                'next_sequence' => $expectedSequence,
            ];
        }, 3);
    }

    public function approve(KioskDevice $device, KioskRecoveryRequest $recovery, User $reviewer, string $reason): KioskRecoveryRequest
    {
        return DB::transaction(function () use ($device, $recovery, $reviewer, $reason): KioskRecoveryRequest {
            $lockedDevice = KioskDevice::query()->whereKey($device->id)->lockForUpdate()->firstOrFail();
            $this->assertActive($lockedDevice);
            $lockedRecovery = KioskRecoveryRequest::query()->whereKey($recovery->id)->lockForUpdate()->firstOrFail();
            abort_unless($lockedRecovery->kiosk_device_id === $lockedDevice->id, 404);
            if ($lockedRecovery->status === 'approved' && $lockedRecovery->approved_until?->isPast()) {
                $lockedRecovery->forceFill(['status' => 'expired'])->save();
            }
            abort_unless(
                in_array($lockedRecovery->status, ['pending', 'expired'], true),
                409,
                $lockedRecovery->status === 'consumed'
                    ? 'This recovery request was already consumed by the kiosk.'
                    : 'This recovery request is already approved and cannot be reviewed again.'
            );

            $lockedRecovery->forceFill([
                'status' => 'approved',
                'reviewed_by' => $reviewer->id,
                'review_reason' => $reason,
                'reviewed_at' => now(),
                'approved_until' => now()->addDay(),
            ])->save();

            return $lockedRecovery->fresh();
        }, 3);
    }

    /**
     * @param  list<array{event_id: string, sequence: int, code: string, occurred_at: string, signature: string, error?: string|null, message?: string|null}>  $events
     * @return list<array{event_id: string, sequence: int, code: string, occurred_at: string, signature: string, error: string|null, message: string|null}>
     */
    private function normalizeEvents(array $events): array
    {
        $normalized = array_map(static fn (array $event): array => [
            'event_id' => $event['event_id'],
            'sequence' => (int) $event['sequence'],
            'code' => trim($event['code']),
            'occurred_at' => $event['occurred_at'],
            'signature' => strtolower($event['signature']),
            'error' => isset($event['error']) ? (string) $event['error'] : null,
            'message' => isset($event['message']) ? (string) $event['message'] : null,
        ], $events);
        usort($normalized, static fn (array $left, array $right): int => $left['sequence'] <=> $right['sequence']);

        return array_values($normalized);
    }

    /**
     * A blocked event may be a valid next event that the server rejected
     * before persisting, or an already-stale sequence conflict caused by an
     * interrupted/duplicate kiosk tab. The latter is safe to discard only
     * after the same exact payload is reviewed by IT. Any event above the
     * current server sequence still has to form one contiguous queue.
     *
     * @param  list<array{event_id: string, sequence: int, code: string, occurred_at: string, error: string|null, message: string|null}>  $events
     */
    private function validateRecoverableSequences(array $events, int $expectedSequence): void
    {
        $future = array_values(array_filter(
            $events,
            static fn (array $event): bool => $event['sequence'] >= $expectedSequence,
        ));

        foreach ($future as $offset => $event) {
            if ($event['sequence'] !== $expectedSequence + $offset) {
                throw ValidationException::withMessages([
                    'blocked_events' => ["Blocked events above server sequence must begin at {$expectedSequence} and remain contiguous."],
                ]);
            }
        }
    }

    /**
     * Recovery is allowed only for the exact signed device events. This makes
     * the IT review payload an immutable representation of what the kiosk
     * originally captured, even when the scan was rejected before it could be
     * saved to the server queue.
     *
     * @param  list<array{event_id: string, sequence: int, code: string, occurred_at: string, signature: string, error: string|null, message: string|null}>  $events
     */
    private function assertEventsAreAuthentic(string $secret, array $events): void
    {
        foreach ($events as $event) {
            try {
                $occurredAt = CarbonImmutable::createFromFormat('!Y-m-d\\TH:i:s.v\\Z', $event['occurred_at'], 'UTC');
                if (! $occurredAt || $occurredAt->format('Y-m-d\\TH:i:s.v\\Z') !== $event['occurred_at']) {
                    throw new \InvalidArgumentException('Invalid timestamp.');
                }
            } catch (\Throwable) {
                throw ValidationException::withMessages([
                    'blocked_events' => ['Recovery events must retain their original canonical UTC timestamps.'],
                ]);
            }

            $expected = $this->tokens->expectedSignature(
                $secret,
                $event['event_id'],
                $event['code'],
                $event['occurred_at'],
                $event['sequence'],
            );
            if (! hash_equals($expected, $event['signature'])) {
                throw ValidationException::withMessages([
                    'blocked_events' => ['One or more recovery events do not match the kiosk-signed scan payload.'],
                ]);
            }
        }
    }

    private function assertActive(KioskDevice $device): void
    {
        abort_if(! $device->is_active || $device->revoked_at !== null, 409, 'A revoked device cannot use queue recovery.');
    }
}
