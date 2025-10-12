<?php

namespace Westel\License;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Blade;
use Westel\License\Services\LicenseService;
use Westel\License\Services\ServerLicenseService;
use Westel\License\Services\ClientLicenseService;
use Westel\License\Services\HardwareFingerprintService;
use Westel\License\Console\LicenseCheckCommand;
use Westel\License\Console\LicenseInstallCommand;

class LicenseServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        // Merge package config
        $this->mergeConfigFrom(
            __DIR__.'/../config/license.php',
            'license'
        );

        // Register services based on mode
        $this->registerServices();

        // Register aliases
        $this->app->alias(LicenseService::class, 'license');
        $this->app->alias(HardwareFingerprintService::class, 'license.fingerprint');
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        // Publish config
        $this->publishes([
            __DIR__.'/../config/license.php' => config_path('license.php'),
        ], 'license-config');

        // Publish migrations (server mode only)
        if (config('license.mode') === 'server') {
            $this->publishes([
                __DIR__.'/Database/Migrations' => database_path('migrations'),
            ], 'license-migrations');

            $this->loadMigrationsFrom(__DIR__.'/Database/Migrations');
        }

        // Register routes
        if (config('license.mode') === 'server' && config('license.server.enable_api_routes', true)) {
            $this->registerRoutes();
        }

        // Register middleware
        if (config('license.middleware.auto_register', true)) {
            $this->registerMiddleware();
        }

        // Register Blade directives
        $this->registerBladeDirectives();

        // Register commands
        if ($this->app->runningInConsole()) {
            $this->commands([
                LicenseCheckCommand::class,
                LicenseInstallCommand::class,
            ]);
        }
    }

    /**
     * Register package services based on mode
     */
    protected function registerServices(): void
    {
        // Register hardware fingerprint service (used by both modes)
        $this->app->singleton(HardwareFingerprintService::class, function ($app) {
            return new HardwareFingerprintService(
                config('license.fingerprint')
            );
        });

        // Register mode-specific service
        $mode = config('license.mode', 'client');

        if ($mode === 'server') {
            // Register ServerLicenseService as both itself and LicenseService
            $this->app->singleton(ServerLicenseService::class, function ($app) {
                return new ServerLicenseService(
                    config('license.server')
                );
            });

            $this->app->singleton(LicenseService::class, function ($app) {
                return $app->make(ServerLicenseService::class);
            });
        } else {
            $this->app->singleton(LicenseService::class, function ($app) {
                return new ClientLicenseService(
                    config('license.client'),
                    $app->make(HardwareFingerprintService::class)
                );
            });
        }
    }

    /**
     * Register API routes (server mode)
     */
    protected function registerRoutes(): void
    {
        Route::group([
            'prefix' => config('license.server.api_prefix', 'api/license'),
            'middleware' => config('license.server.enable_cors') ? ['api', 'cors'] : ['api'],
        ], function () {
            $this->loadRoutesFrom(__DIR__.'/routes/api.php');
        });
    }

    /**
     * Register middleware
     */
    protected function registerMiddleware(): void
    {
        $router = $this->app['router'];

        foreach (config('license.middleware.aliases', []) as $alias => $middleware) {
            $router->aliasMiddleware($alias, $middleware);
        }
    }

    /**
     * Register Blade directives
     */
    protected function registerBladeDirectives(): void
    {
        // @license('feature_key')
        Blade::if('license', function ($featureKey) {
            try {
                return app(LicenseService::class)->hasFeature($featureKey);
            } catch (\Exception $e) {
                return false;
            }
        });

        // @licenseValid
        Blade::if('licenseValid', function () {
            try {
                return app(LicenseService::class)->isValid();
            } catch (\Exception $e) {
                return false;
            }
        });

        // @licenseExpired
        Blade::if('licenseExpired', function () {
            try {
                $service = app(LicenseService::class);
                $info = $service->getLicenseInfo();
                return $info['is_expired'] ?? false;
            } catch (\Exception $e) {
                return true;
            }
        });

        // @licenseGracePeriod
        Blade::if('licenseGracePeriod', function () {
            try {
                $service = app(LicenseService::class);
                $info = $service->getLicenseInfo();
                return ($info['is_expired'] ?? false) && ($info['in_grace_period'] ?? false);
            } catch (\Exception $e) {
                return false;
            }
        });
    }
}
