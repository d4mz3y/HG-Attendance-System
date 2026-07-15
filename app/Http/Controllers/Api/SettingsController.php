<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Services\AppConfigService;
use Illuminate\Http\Request;

class SettingsController extends Controller
{
    public function show(AppConfigService $config)
    {
        $settings = $config->allSettings();

        $extra = [
            'enable_alerts' => Setting::getValue('enable_alerts', '1') === '1',
            'enable_scheduled_reports' => Setting::getValue('enable_scheduled_reports', '0') === '1',
            'report_email' => Setting::getValue('report_email', ''),
            'report_frequency' => Setting::getValue('report_frequency', 'daily'),
            'scan_allowed_ips' => Setting::getValue('scan_allowed_ips', ''),
            'kiosk_lockdown' => Setting::getValue('kiosk_lockdown', '0') === '1',
            'grace_period_minutes' => (int) Setting::getValue('grace_period_minutes', '0'),
            'dark_mode_default' => Setting::getValue('dark_mode_default', '0') === '1',
        ];

        return response()->json(array_merge($settings, $extra));
    }

    public function update(Request $request)
    {
        $user = $request->user();
        $data = $request->validate([
            'shift_start' => ['required', 'date_format:H:i'],
            'shift_end' => ['required', 'date_format:H:i'],
            'scan_debounce_seconds' => ['required', 'integer', 'min:0', 'max:600'],
            'branch_label' => ['required', 'string', 'max:255'],
            'grace_period_minutes' => ['required', 'integer', 'min:0', 'max:180'],
            'enable_alerts' => ['nullable', 'boolean'],
            'enable_scheduled_reports' => ['nullable', 'boolean'],
            'report_email' => ['nullable', 'email', 'max:255'],
            'report_frequency' => ['nullable', 'in:daily,weekly,monthly'],
            'scan_allowed_ips' => ['nullable', 'string', 'max:1024'],
            'kiosk_lockdown' => ['nullable', 'boolean'],
            'dark_mode_default' => ['nullable', 'boolean'],
        ]);

        if (! $user->isSuperAdmin()) {
            $data['kiosk_lockdown'] = Setting::getValue('kiosk_lockdown', '0');
        }

        foreach ($data as $key => $value) {
            if (is_bool($value)) {
                Setting::setValue($key, $value ? '1' : '0');
            } elseif (is_string($value) || is_numeric($value)) {
                Setting::setValue($key, (string) $value);
            }
        }

        return response()->json((new AppConfigService)->allSettings());
    }
}
