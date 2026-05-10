<?php

namespace Khaled\Ticketing;

use Illuminate\Support\Facades\Route;
use Khaled\Ticketing\Contracts\TicketAccessResolver;
use Khaled\Ticketing\Contracts\TicketOwnerResolver;
use Khaled\Ticketing\Support\DefaultTicketAccessResolver;
use Khaled\Ticketing\Support\DefaultTicketOwnerResolver;
use Illuminate\Support\ServiceProvider;

class TicketingServiceProvider extends ServiceProvider
{
    public function boot()
    {
        $this->loadRoutesFrom(__DIR__ . '/../routes/web.php');
        Route::middleware('api')->prefix('api')->group(__DIR__ . '/../routes/api.php');
        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'ticketing');
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
        $this->mergeConfigFrom(__DIR__ . '/../config/ticketing.php', 'ticketing');
        $this->publishes([
            __DIR__ . '/../config/ticketing.php' => config_path('ticketing.php'),
            __DIR__ . '/../resources/views' => resource_path('views/vendor/ticketing'),
            __DIR__ . '/../database/migrations' => database_path('migrations'),
        ], 'ticketing');
    }

    public function register()
    {
        $this->app->bind(TicketAccessResolver::class, DefaultTicketAccessResolver::class);
        $this->app->bind(TicketOwnerResolver::class, DefaultTicketOwnerResolver::class);
    }
}