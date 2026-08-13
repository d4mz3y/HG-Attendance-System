<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        if ($this->app->environment('production')) {
            URL::forceRootUrl((string) config('app.url'));
            URL::forceScheme('https');
        }

        RateLimiter::for('login', function (Request $request): array {
            $username = Str::lower(trim((string) $request->input('username')));

            return [
                Limit::perMinute(20)->by('login-ip:'.$request->ip()),
                Limit::perMinute(5)->by('login-account:'.$username.'|'.$request->ip()),
            ];
        });

        RateLimiter::for('scan', function (Request $request): array {
            $deviceId = explode('.', (string) $request->header('X-Device-Token'), 2)[0] ?: $request->ip();

            return [
                Limit::perMinute(300)->by('scan-ip:'.$request->ip()),
                Limit::perMinute(120)->by('scan-client:'.$deviceId),
            ];
        });
    }
}
