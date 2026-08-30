<?php

namespace FlexiLaravel;

use FlexiLaravel\Console\Commands\FlexiAddCommand;
use FlexiLaravel\Console\Commands\FlexiBuildCommand;
use FlexiLaravel\Console\Commands\FlexiFixIconsCommand;
use FlexiLaravel\Console\Commands\FlexiInitCommand;
use FlexiLaravel\Console\Commands\FlexiListCommand;
use FlexiLaravel\Console\Commands\FlexiPreviewCommand;
use FlexiLaravel\Console\Commands\FlexiSearchCommand;
use Illuminate\Support\ServiceProvider;

class FlexiServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                FlexiInitCommand::class,
                FlexiAddCommand::class,
                FlexiListCommand::class,
                FlexiSearchCommand::class,
                FlexiPreviewCommand::class,
                FlexiBuildCommand::class,
                FlexiFixIconsCommand::class,
            ]);
        }
    }
}
