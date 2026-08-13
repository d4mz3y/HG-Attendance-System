<?php

return [
    'trial_start' => env('SUBSCRIPTION_TRIAL_START', '2026-09-01'),
    'trial_end' => env('SUBSCRIPTION_TRIAL_END', '2027-09-01'),
    'warning_days' => (int) env('SUBSCRIPTION_WARNING_DAYS', 30),
    'daily_scan_cap' => (int) env('SUBSCRIPTION_DAILY_SCAN_CAP', 20),
    'currency' => strtoupper((string) env('PAYSTACK_CURRENCY', 'USD')),
    'billing_email' => env('BILLING_EMAIL'),
    'plans' => [
        'monthly' => [
            'amount' => (int) env('PAYSTACK_MONTHLY_AMOUNT', 2500),
            'label' => env('PAYSTACK_MONTHLY_LABEL', 'Monthly'),
            'months' => 1,
        ],
        'yearly' => [
            'amount' => (int) env('PAYSTACK_YEARLY_AMOUNT', 28000),
            'label' => env('PAYSTACK_YEARLY_LABEL', 'Yearly'),
            'months' => 12,
        ],
    ],
];
