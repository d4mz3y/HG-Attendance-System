<?php

namespace App\Services;

use App\Models\KioskDevice;
use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\IpUtils;

class DeviceTokenService
{
    /** @return array{device: KioskDevice, token: string} */
    public function issue(KioskDevice $device, bool $activate = false): array
    {
        if (($device->revoked_at !== null || ! $device->is_active) && ! $activate) {
            throw new \RuntimeException('A revoked device cannot be rotated. Register a new device instead.');
        }

        $secret = Str::password(64, letters: true, numbers: true, symbols: false, spaces: false);
        $token = $device->identifier.'.'.$secret;

        $device->forceFill([
            'token_hash' => hash('sha256', $secret),
            'token_last_four' => substr($secret, -4),
            'is_active' => true,
            'revoked_at' => null,
        ])->save();

        return ['device' => $device->fresh(), 'token' => $token];
    }

    /** @return array{device: KioskDevice, secret: string}|null */
    public function authenticate(Request $request, ?string $requiredType = null): ?array
    {
        $provided = trim((string) $request->header('X-Device-Token', ''));
        if ($provided === '' || ! str_contains($provided, '.')) {
            return null;
        }

        [$identifier, $secret] = explode('.', $provided, 2);
        if (! Str::isUuid($identifier) || strlen($secret) < 32) {
            return null;
        }

        $device = KioskDevice::query()
            ->where('identifier', $identifier)
            ->where('is_active', true)
            ->whereNull('revoked_at')
            ->first();

        if (! $device || ($requiredType !== null && $device->type !== $requiredType)) {
            return null;
        }

        if (! hash_equals($device->token_hash, hash('sha256', $secret))) {
            return null;
        }

        $ability = $requiredType === 'biometric' ? 'biometric' : 'scan';
        // The one browser scanner is a fixed reception terminal. It must be
        // pinned to an explicit source address/range even if the global scan
        // allow-list is intentionally broad for other integrations.
        if ($device->isReceptionTerminal()
            && ! $this->receptionIpMatches($request->ip(), $device->allowed_ips)) {
            return null;
        }
        if (! $device->can($ability)
            || (! $device->isReceptionTerminal() && ! $this->ipIsAllowed($request->ip(), $device->allowed_ips))) {
            return null;
        }

        if ($device->last_seen_at === null || $device->last_seen_at->lt(now()->subMinutes(2)) || $device->last_ip !== $request->ip()) {
            $device->forceFill([
                'last_seen_at' => now(),
                'last_ip' => $request->ip(),
            ])->saveQuietly();
        }

        return ['device' => $device, 'secret' => $secret];
    }

    public function globalIpIsAllowed(?string $ip): bool
    {
        return $this->ipIsAllowed($ip, Setting::getValue('scan_allowed_ips', ''));
    }

    public function ipIsAllowed(?string $ip, ?string $rules): bool
    {
        if ($ip === null) {
            return false;
        }

        $allowed = $this->parseIpRules($rules);
        if ($allowed === []) {
            return true;
        }

        try {
            return IpUtils::checkIp($ip, $allowed);
        } catch (\InvalidArgumentException) {
            return false;
        }
    }

    /**
     * The browser kiosk has one physical home: the reception computer.
     * Unlike global scanning policy, a per-terminal CIDR would let another
     * host on that range claim an unpaired scanner, so this deliberately
     * accepts one exact IPv4 or IPv6 address only.
     */
    public function receptionIpMatches(?string $ip, ?string $configuredIp): bool
    {
        $configured = trim((string) $configuredIp);
        if ($ip === null
            || $configured === ''
            || str_contains($configured, '/')
            || preg_match('/[,;\s]/', $configured) === 1
            || filter_var($ip, FILTER_VALIDATE_IP) === false
            || filter_var($configured, FILTER_VALIDATE_IP) === false) {
            return false;
        }

        $requestedPacked = inet_pton($ip);
        $configuredPacked = inet_pton($configured);

        return $requestedPacked !== false
            && $configuredPacked !== false
            && hash_equals($configuredPacked, $requestedPacked);
    }

    /** @return list<string> */
    public function parseIpRules(?string $rules): array
    {
        if ($rules === null || trim($rules) === '') {
            return [];
        }

        return array_values(array_filter(
            preg_split('/[\s,;]+/', trim($rules)) ?: [],
            static fn (string $value): bool => $value !== ''
        ));
    }

    public function canonicalEvent(string $eventId, string $code, string $occurredAt, int $sequence): string
    {
        return $eventId."\n".$code."\n".$occurredAt."\n".$sequence;
    }

    public function expectedSignature(string $secret, string $eventId, string $code, string $occurredAt, int $sequence): string
    {
        return hash_hmac('sha256', $this->canonicalEvent($eventId, $code, $occurredAt, $sequence), $secret);
    }
}
