<?php

namespace App\Services;

use App\Models\Setting;
use Carbon\Carbon;

class AppConfigService
{
    public function shiftStart(): Carbon
    {
        $t = Setting::getValue('shift_start', '08:00') ?? '08:00';

        return Carbon::createFromFormat('H:i', $t) ?? Carbon::createFromTime(8, 0);
    }

    public function shiftEnd(): Carbon
    {
        $t = Setting::getValue('shift_end', '17:00') ?? '17:00';

        return Carbon::createFromFormat('H:i', $t) ?? Carbon::createFromTime(17, 0);
    }

    public function scanDebounceSeconds(): int
    {
        return (int) Setting::getValue('scan_debounce_seconds', '120');
    }

    public function branchLabel(): string
    {
        return Setting::getValue('branch_label', 'Headquarters');
    }

    public function allSettings(): array
    {
        return [
            'shift_start' => Setting::getValue('shift_start', '08:00'),
            'shift_end' => Setting::getValue('shift_end', '17:00'),
            'scan_debounce_seconds' => (int) Setting::getValue('scan_debounce_seconds', '120'),
            'branch_label' => Setting::getValue('branch_label', 'Headquarters'),
            'grace_period_minutes' => (int) Setting::getValue('grace_period_minutes', '0'),
        ];
    }
}
