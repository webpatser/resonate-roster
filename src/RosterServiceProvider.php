<?php

namespace Webpatser\ResonateRoster;

use Illuminate\Support\ServiceProvider;

/**
 * Wires the roster into a host Laravel application.
 *
 * It registers the read side ({@see RoomRoster}) as a singleton and publishes
 * the config. The write side ({@see RedisRosterPlugin}) is not bound here: it
 * is instantiated by Resonate from the `plugins` array in `config/reverb.php`.
 */
class RosterServiceProvider extends ServiceProvider
{
    /**
     * Register the package services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/resonate-roster.php', 'resonate-roster');

        $this->app->singleton(RoomRoster::class, function ($app) {
            return new RoomRoster($app['config']->get('resonate-roster', []));
        });
    }

    /**
     * Bootstrap the package services.
     */
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/resonate-roster.php' => $this->app->configPath('resonate-roster.php'),
            ], 'resonate-roster-config');
        }
    }
}
