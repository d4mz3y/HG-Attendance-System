<?php

namespace Tests;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;

trait CreatesApplication
{
    public function createApplication(): Application
    {
        $testConfigCache = __DIR__.'/../bootstrap/cache/testing-config.php';
        putenv("APP_CONFIG_CACHE={$testConfigCache}");
        $_ENV['APP_CONFIG_CACHE'] = $testConfigCache;
        $_SERVER['APP_CONFIG_CACHE'] = $testConfigCache;

        $app = require __DIR__.'/../bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();

        return $app;
    }
}
