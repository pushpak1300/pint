<?php

namespace App\Providers;

use App\Actions\EnsurePrettierIsConfigured;
use App\BladeFormatter;
use App\Project;
use App\Repositories\ConfigurationJsonRepository;
use App\Support\Mago;
use App\Support\Prettier;
use App\ValueObjects\ErrorsManager;
use Illuminate\Support\ServiceProvider;
use Symfony\Component\EventDispatcher\EventDispatcher;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        //
    }

    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        $this->app->singleton(Mago::class);

        $this->app->singleton(ErrorsManager::class, function () {
            return new ErrorsManager;
        });

        $this->app->singleton(EventDispatcher::class, function () {
            return new EventDispatcher;
        });

        $this->app->singleton(Prettier::class, function () {
            return new Prettier(Project::path());
        });

        $this->app->singleton(EnsurePrettierIsConfigured::class, function ($app) {
            return new EnsurePrettierIsConfigured(
                $app->make(Prettier::class),
                $app->make(ConfigurationJsonRepository::class),
            );
        });

        $this->app->terminating(function () {
            $this->app->make(Prettier::class)->ensureTerminated();
        });

        $this->app->bind(BladeFormatter::class, function ($app) {
            return new BladeFormatter($app->make(Prettier::class));
        });
    }
}
