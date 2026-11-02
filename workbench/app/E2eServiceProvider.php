<?php

namespace Workbench\App;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use Prolaxu\Osync\Tests\Fixtures\Mantra;
use Prolaxu\Osync\Tests\Fixtures\MantraCollection;
use Prolaxu\Osync\Tests\Fixtures\Note;
use Prolaxu\Osync\Tests\Fixtures\NoteCollection;
use Prolaxu\Osync\Tests\Fixtures\User;

/**
 * A throwaway server for the Dart client's end-to-end test: the bearer token
 * is the user's name, and two routes let the test edit records the way a
 * website or admin panel would.
 */
final class E2eServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        config([
            'database.default' => 'e2e',
            'database.connections.e2e' => ['driver' => 'sqlite', 'database' => dirname(__DIR__).'/e2e.sqlite', 'foreign_key_constraints' => true],
            'osync.collections' => [NoteCollection::class, MantraCollection::class],
            'osync.route.middleware' => ['api', 'auth:e2e'],
            'osync.page_size' => 3,
            'auth.guards.e2e' => ['driver' => 'e2e'],
        ]);
    }

    public function boot(): void
    {
        Auth::viaRequest('e2e', fn (Request $request): ?User => $request->bearerToken() === null
            ? null
            : User::firstOrCreate(['name' => $request->bearerToken()]));

        Route::post('e2e/reset', function () {
            foreach (['osync_changes', 'osync_scopes', 'notes', 'mantras', 'users'] as $table) {
                Schema::dropIfExists($table);
            }
            Schema::create('users', fn (Blueprint $t) => [$t->id(), $t->string('name')]);
            Schema::create('notes', function (Blueprint $t): void {
                $t->id();
                $t->string('sync_scope', 191);
                $t->string('sync_id', 191);
                $t->string('title');
                $t->text('body')->nullable();
                $t->timestamps();
                $t->softDeletes();
            });
            Schema::create('mantras', function (Blueprint $t): void {
                $t->id();
                $t->string('sync_scope', 191)->default('global');
                $t->string('sync_id', 191);
                $t->string('text');
                $t->timestamps();
            });
            (require __DIR__.'/../../database/migrations/2026_11_02_000000_create_osync_tables.php')->up();

            return ['ok' => true];
        });

        Route::post('e2e/web-edit', function (Request $request) {
            $note = Note::where('sync_id', $request->string('id'))->firstOrFail();
            $note->update(['title' => $request->string('title')]);

            return ['ok' => true];
        });

        Route::post('e2e/publish-mantra', fn (Request $request) => Mantra::create(['text' => $request->string('text')]));
    }
}
