<?php

namespace Nepicsoft\Osync;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Validation\Factory as ValidationFactory;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\ServiceProvider;
use Nepicsoft\Osync\Console\PruneCommand;
use Nepicsoft\Osync\Contracts\SyncCollection;

final class OsyncServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/osync.php', 'osync');

        $this->app->singleton(Journal::class, fn (Application $app): Journal => new Journal(
            $app->make(DatabaseManager::class)->connection(config('osync.connection')),
            (string) config('osync.node', 'server'),
        ));

        $this->app->singleton(CollectionRegistry::class, fn (Application $app): CollectionRegistry => new CollectionRegistry(
            array_map(
                fn (string $class): SyncCollection => $app->make($class),
                config('osync.collections', []),
            ),
        ));

        $this->app->bind(SyncEngine::class, fn (Application $app): SyncEngine => new SyncEngine(
            $app->make(CollectionRegistry::class),
            $app->make(Journal::class),
            $app->make(ValidationFactory::class),
            (int) config('osync.page_size', 500),
        ));
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        if (config('osync.route.enabled')) {
            $this->loadRoutesFrom(__DIR__.'/../routes/osync.php');
        }

        if ($this->app->runningInConsole()) {
            $this->commands([PruneCommand::class]);
            $this->publishes([__DIR__.'/../config/osync.php' => config_path('osync.php')], 'osync-config');
            $this->publishes([__DIR__.'/../database/migrations' => database_path('migrations')], 'osync-migrations');
        }
    }
}
