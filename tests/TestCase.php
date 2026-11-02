<?php

namespace Nepicsoft\Osync\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Nepicsoft\Osync\OsyncServiceProvider;
use Nepicsoft\Osync\Tests\Fixtures\MantraCollection;
use Nepicsoft\Osync\Tests\Fixtures\NoteCollection;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    use RefreshDatabase;

    protected function getPackageProviders($app): array
    {
        return [OsyncServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('osync.collections', [NoteCollection::class, MantraCollection::class]);
        $app['config']->set('osync.route.middleware', ['api', 'auth']);
        $app['config']->set('auth.providers.users.model', Fixtures\User::class);
    }

    protected function defineDatabaseMigrations(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
        });
        Schema::create('notes', function (Blueprint $table): void {
            $table->id();
            $table->string('sync_scope', 191);
            $table->string('sync_id', 191);
            $table->string('title');
            $table->text('body')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['sync_scope', 'sync_id']);
        });
        Schema::create('mantras', function (Blueprint $table): void {
            $table->id();
            $table->string('sync_scope', 191)->default('global');
            $table->string('sync_id', 191);
            $table->string('text');
            $table->timestamps();
        });
    }
}
