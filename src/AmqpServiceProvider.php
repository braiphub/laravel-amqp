<?php

namespace Braiphub\Amqp;

use Illuminate\Support\ServiceProvider;

class AmqpServiceProvider extends ServiceProvider
{
    protected bool $defer = true;

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        $this->app->bind('Amqp', 'Braiphub\Amqp\Amqp');
        if (! class_exists('Amqp')) {
            class_alias('Braiphub\Amqp\Facades\Amqp', 'Amqp');
        }

        $this->publishes([
            __DIR__ . '/../config/amqp.php' => config_path('amqp.php'),
        ]);
    }

    /**
     * Register the application services.
     *
     * @return void
     */
    public function register()
    {
    }

    /**
     * Get the services provided by the provider.
     *
     * @return array
     */
    public function provides(): array
    {
        return ['Amqp'];
    }
}