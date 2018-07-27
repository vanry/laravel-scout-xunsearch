<?php

namespace Vanry\Scout;

use Laravel\Scout\EngineManager;
use Illuminate\Support\ServiceProvider;
use Vanry\Scout\Engines\XunsearchEngine;

class XunsearchScoutServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        $this->app[EngineManager::class]->extend('xunsearch', function ($app) {
            return new XunsearchEngine($this->app['config']['scout.xunsearch']);
        });
    }
}
