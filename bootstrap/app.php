<?php

use App\Http\Middleware\AuditMutation;
use App\Http\Middleware\AuthenticateDevice;
use App\Http\Middleware\AuthenticateScanClient;
use App\Http\Middleware\EnsureActiveUser;
use App\Http\Middleware\EnsurePasswordChanged;
use App\Http\Middleware\EnsurePermission;
use App\Http\Middleware\SubscriptionGate;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->trustHosts(
            at: static function (): array {
                $configuredHost = parse_url((string) config('app.url'), PHP_URL_HOST);

                return array_values(array_filter([
                    $configuredHost ? '^'.preg_quote($configuredHost, '/').'$' : null,
                    '^127\\.0\\.0\\.1$',
                    '^localhost$',
                ]));
            },
            subdomains: false,
        );

        $middleware->alias([
            'subscription.gate' => SubscriptionGate::class,
            'device' => AuthenticateDevice::class,
            'scan.client' => AuthenticateScanClient::class,
            'active' => EnsureActiveUser::class,
            'password.changed' => EnsurePasswordChanged::class,
            'permission' => EnsurePermission::class,
            'audit.mutations' => AuditMutation::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
