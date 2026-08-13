<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Services\AppConfigService;
use App\Services\ScheduleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SettingsController extends Controller
{
    public function show(AppConfigService $config): JsonResponse
    {
        return response()->json($config->allSettings());
    }

    public function update(Request $request, AppConfigService $config, ScheduleService $schedules): JsonResponse
    {
        abort_unless($request->user()->canManageSystem(), 403, 'Only IT managers or the super administrator can change system settings.');

        $data = $request->validate([
            'shift_start' => ['required', 'date_format:H:i'],
            'shift_end' => ['required', 'date_format:H:i'],
            'default_work_days' => ['required', 'array', 'min:1', 'max:7'],
            'default_work_days.*' => ['required', 'integer', 'between:0,6', 'distinct'],
            'scan_debounce_seconds' => ['required', 'integer', 'min:0', 'max:30'],
            'scan_cooldown_seconds' => ['required', 'integer', 'min:0', 'max:43200'],
            'offline_max_age_hours' => ['required', 'integer', 'min:1', 'max:168'],
            'scan_clock_skew_seconds' => ['required', 'integer', 'min:0', 'max:900'],
            'branch_label' => ['required', 'string', 'max:255'],
            'grace_period_minutes' => ['required', 'integer', 'min:0', 'max:180'],
            'default_break_minutes' => ['required', 'integer', 'min:0', 'max:480'],
            'enable_alerts' => ['required', 'boolean'],
            'missed_clock_out_alert_minutes' => ['required', 'integer', 'min:0', 'max:1440'],
            'absence_alert_minutes' => ['required', 'integer', 'min:0', 'max:1440'],
            'enable_scheduled_reports' => ['required', 'boolean'],
            'report_email' => ['nullable', 'required_if:enable_scheduled_reports,true', 'email:rfc', 'max:255'],
            'report_frequency' => ['required', Rule::in(['daily', 'weekly', 'monthly'])],
            'scan_allowed_ips' => ['nullable', 'string', 'max:2048'],
            'dark_mode_default' => ['required', 'boolean'],
        ]);

        if ($data['shift_start'] === $data['shift_end']) {
            throw ValidationException::withMessages([
                'shift_end' => ['Shift end must differ from shift start. An earlier end time represents an overnight shift.'],
            ]);
        }

        $this->validateCidrs((string) ($data['scan_allowed_ips'] ?? ''));
        $data['default_work_days'] = json_encode(array_values($data['default_work_days']), JSON_THROW_ON_ERROR);

        DB::transaction(function () use ($data, $request, $schedules): void {
            foreach ($data as $key => $value) {
                Setting::setValue($key, is_bool($value) ? ($value ? '1' : '0') : (string) $value);
            }
            $schedules->recordDefaultVersion($request->user()->id);
        });

        return response()->json($config->allSettings());
    }

    private function validateCidrs(string $value): void
    {
        foreach (array_filter(preg_split('/[,;\s]+/', trim($value)) ?: []) as $cidr) {
            [$address, $prefix] = array_pad(explode('/', $cidr, 2), 2, null);
            if (filter_var($address, FILTER_VALIDATE_IP) === false) {
                throw ValidationException::withMessages(['scan_allowed_ips' => ["Invalid IP address or CIDR: {$cidr}"]]);
            }

            if ($prefix !== null) {
                $max = str_contains($address, ':') ? 128 : 32;
                if (! ctype_digit($prefix) || (int) $prefix < 0 || (int) $prefix > $max) {
                    throw ValidationException::withMessages(['scan_allowed_ips' => ["Invalid CIDR prefix: {$cidr}"]]);
                }
            }
        }
    }
}
