<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Karsjen\StatelessQueue\Tests\Support\Console\RunRealPushE2ECommand;
use Karsjen\StatelessQueue\Tests\Support\Console\WaitForEmulatorsCommand;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                WaitForEmulatorsCommand::class,
                RunRealPushE2ECommand::class,
            ]);
        }
    }
}
