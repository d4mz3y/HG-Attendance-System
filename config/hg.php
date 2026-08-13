<?php

return [
    // Keep the legacy environment fallback so existing office deployments
    // retain their session length while new deployments use the clearer name.
    'auth_token_expiration_minutes' => (int) env('PORTAL_SESSION_EXPIRATION_MINUTES', env('SANCTUM_EXPIRATION_MINUTES', 480)),

    // Remembered portal sessions are only issued after the user explicitly
    // opts in on a private computer. Ordinary sign-in remains short-lived.
    'auth_remember_expiration_days' => (int) env('SANCTUM_REMEMBER_EXPIRATION_DAYS', 30),

    'company_code' => 'HGL',

    'company_codes' => [
        'Hogan Guards' => 'HGL',
        'Hogan Technology' => 'HTL',
        'Hogan Logistics' => 'HLL',
        'Hogan Cleaning' => 'HCL',
        'Hogan Maintenance' => 'HMN',
        'Hogan Security' => 'HSC',
    ],

    'default_branch_code' => 'LA',

    'branch_codes' => [
        'Lagos (HQ)' => 'LA',
        'Abuja' => 'ABJ',
        'Ibadan' => 'IBD',
    ],

    'departments' => [
        'Board of Directors',
        'Management',
        'Operations',
        'Admin',
        'Finance',
        'Security',
    ],

    'department_codes' => [
        'Board of Directors' => 'BOD',
        'Management' => 'MGT',
        'Operations' => 'OPS',
        'Admin' => 'ADM',
        'Finance' => 'FIN',
        'Security' => 'SEC',
    ],

    'staff_id_pattern' => '/^([A-Z]{2,4})\/([A-Z]{2,4})\/([A-Z]{2,4})\/(\d{3,6})$/',

    'report_max_days' => (int) env('REPORT_MAX_DAYS', 366),
    'report_max_matrix_cells' => (int) env('REPORT_MAX_MATRIX_CELLS', 100000),
    'report_schedule_time' => env('REPORT_SCHEDULE_TIME', '06:00'),

    'settings_defaults' => [
        'shift_start' => '08:00',
        'shift_end' => '17:00',
        'default_work_days' => '[1,2,3,4,5]',
        'default_break_minutes' => '60',
        'scan_debounce_seconds' => '2',
        'scan_cooldown_seconds' => '1800',
        'offline_max_age_hours' => '72',
        'scan_clock_skew_seconds' => '300',
        'branch_label' => 'Headquarters',
        'grace_period_minutes' => '0',
        'enable_alerts' => '1',
        'missed_clock_out_alert_minutes' => '60',
        'absence_alert_minutes' => '60',
        'enable_scheduled_reports' => '0',
        'report_email' => '',
        'report_frequency' => 'daily',
        'scan_allowed_ips' => '',
        'dark_mode_default' => '0',
        'subscription_status' => 'unpaid',
        'subscription_plan' => '',
        'subscription_expiry' => '',
        'subscription_last_reference' => '',
        'branches' => json_encode(['Lagos (HQ)', 'Abuja', 'Ibadan']),
        'companies' => json_encode(['Hogan Guards', 'Hogan Technology', 'Hogan Logistics', 'Hogan Cleaning', 'Hogan Maintenance', 'Hogan Security']),
        'departments' => json_encode(['Board of Directors', 'Management', 'Operations', 'Admin', 'Finance', 'Security']),
        'branch_codes' => json_encode([
            'Lagos (HQ)' => 'LA',
            'Abuja' => 'ABJ',
            'Ibadan' => 'IBD',
        ]),
        'company_codes' => json_encode([
            'Hogan Guards' => 'HGL',
            'Hogan Technology' => 'HTL',
            'Hogan Logistics' => 'HLL',
            'Hogan Cleaning' => 'HCL',
            'Hogan Maintenance' => 'HMN',
            'Hogan Security' => 'HSC',
        ]),
        'department_codes' => json_encode([
            'Board of Directors' => 'BOD',
            'Management' => 'MGT',
            'Operations' => 'OPS',
            'Admin' => 'ADM',
            'Finance' => 'FIN',
            'Security' => 'SEC',
        ]),
    ],
];
