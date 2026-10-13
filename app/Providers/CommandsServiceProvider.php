<?php

namespace App\Providers;

use App\Actions\ElaborateSummary;
use App\Actions\EnsurePrettierIsConfigured;
use App\Actions\FixCode;
use App\Commands\DefaultCommand;
use App\Commands\QualityCommand;
use Illuminate\Support\ServiceProvider;

class CommandsServiceProvider extends ServiceProvider
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
        foreach ([DefaultCommand::class, QualityCommand::class] as $command) {
            $this->app->bindMethod([$command, 'handle'], function ($command) {
                return $command->handle(
                    resolve(FixCode::class),
                    resolve(ElaborateSummary::class),
                    resolve(EnsurePrettierIsConfigured::class),
                );
            });
        }
    }
}
