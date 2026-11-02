# osync

**osync** for Laravel (12/13, PHP 8.3+): the server half of offline-first, two-way sync with on-device databases.

The Flutter/Dart client is **[osync-dart](https://github.com/prolaxu/osync-dart)**.

## Install

Server, in the Laravel app's `composer.json`:

```json
"repositories": [{ "type": "vcs", "url": "https://github.com/prolaxu/osync" }],
"require": { "nepicsoft/osync": "^0.3" }
```

Then `php artisan migrate`.

## What it does

- **Works offline.** Writes land in the local database and an outbox in one transaction, so the app reads its own writes at once with or without a network.
- **Incremental pull.** Each scope (one user's data, or `global` content) has a version counter that only grows. A device keeps a cursor per collection and asks for changes past it, page by page.
- **Never skips a change.** Every server write takes the scope's row lock, bumps the counter and journals the change under the new version, so versions commit in order and there are no gaps.
- **Handles conflicts.** Every write carries a hybrid logical clock stamp, and the later stamp wins on the server and on every device. The stamp stays correct when a phone's clock is wrong or jumps backwards.
- **Handles deletes.** Deletes are kept as tombstones, so an older edit arriving late can't bring a deleted record back. `osync:prune` drops tombstones after the retention window, and a device offline longer than that gets `reset` and reloads the collection, keeping its unsent work.
- **Safe to retry.** Resending a change is a no-op, so a sync that died halfway just runs again.
- **Settles losers.** A write that lost is rejected with the winning version attached, so the device that sent it converges even when its cursor is already past the winner.
- **Journals server edits.** A `Syncable` model journals its own saves and deletes, so website, admin and job edits reach devices like any other change.
- **Validates.** Each collection has Laravel rules, and only validated fields are written. Invalid data comes back with its field errors.
- **Read-only content.** A collection can be marked read-only for published content (mantras, rashifal) that devices only pull.
- **Small journal.** Only a record's latest change is kept, so the journal stays about as large as the data, and a pull from cursor 0 is a full snapshot.
- **Works with osync-dart.** The client keeps a SQLite store with an outbox and handles background syncing; see [osync-dart](https://github.com/prolaxu/osync-dart).

## Efficiency

Measured with the package's benchmark (`vendor/bin/pest --group=bench`), one device pushing 500 changes:

| | v0.2 | now |
|---|---|---|
| Queries for the push | 3,501 | **12** with `$bulk`, 510 with per-model saves |
| Time for the push | 932 ms | **131 ms** with `$bulk` |
| Push response | 71 KB (every change sent back) | **125 bytes** |
| 500-change pull on the wire | 71 KB | **4.3 KB** gzipped |
| A sync with nothing new | 2 queries | 2 queries |

How:
- **One batch per push.** A collection's whole push commits under one scope lock: one read of what the records hold, one bulk journal upsert per 200 changes, and one counter update.
- **No echo.** The device sends its HLC node, and its pull leaves out changes that came from that node. The device wrote them, so it already has them. After a `reset` the device gets everything, its own changes included.
- **Bulk writes.** `protected bool $bulk = true;` on an `EloquentCollection` writes a batch with a few `upsert`/`update` statements instead of one model save per record. It skips model events and casts, so use it when the payload maps straight onto columns, and give the table a unique index on (`sync_scope`, `sync_id`). Partial payloads only touch the columns they name.
- **Gzip both ways.** Gzipped request bodies are inflated (capped at 32 MB), and responses over 1 KB are gzipped when the client accepts it. Turn it off with `'compression' => false` if the web server already does both.
- **Cheap envelope checks.** The envelope is checked by hand, which costs microseconds a change; Laravel's wildcard rules took 186 ms for 500. Only each collection's own `rules()` go through the validator.
- **Lean JSON.** Deletes carry no `data` key, and `rejected` is left out when empty.
- **Indexed reads.** Pulls read `(scope, collection, version)` through an index and select only the columns they send.

## Protocol (v1)

`POST /api/sync` with any auth middleware you configure.

```jsonc
// request
{
  "protocol": 1,
  "node": "dk3j…",            // optional: this device's HLC node, so its own changes are not sent back
  "collections": {
    "notes": {
      "cursor": 41,
      "changes": [
        { "id": "n1", "op": "upsert", "hlc": "001730000000123:00000:dk3j…", "data": { "title": "…" } },
        { "id": "n2", "op": "delete", "hlc": "001730000000124:00000:dk3j…" }
      ]
    }
  }
}

// response
{
  "protocol": 1,
  "server_time": 1730000000500,
  "collections": {
    "notes": {
      "cursor": 44,
      "reset": false,
      "has_more": false,
      "changes": [ { "id": "n1", "op": "upsert", "hlc": "…", "data": { … } } ],
      "rejected": [ { "id": "n9", "hlc": "…", "reason": "stale", "current": { … } } ]
    }
  }
}
```

An HLC is `<15-digit ms>:<5-digit counter>:<node>`. It is fixed-width up to the node, so string order is clock order on both sides. `reason` is `stale` (with `current`), `invalid` (with `errors`) or `read_only`. A delete has no `data` key, and `rejected` is absent when empty. Bodies may be gzipped (`Content-Encoding: gzip`) in either direction.

## Server

```php
// config/osync.php (php artisan vendor:publish --tag=osync-config)
'collections' => [App\Sync\ChartCollection::class, App\Sync\MantraCollection::class],
'route' => ['uri' => 'api/v1/sync', 'middleware' => ['api', 'auth:sanctum']],
```

```php
final class SavedChart extends Model
{
    use Syncable, SoftDeletes;          // table needs sync_scope + sync_id columns

    public function syncCollection(): string { return 'charts'; }
}

final class ChartCollection extends EloquentCollection
{
    protected function model(): string { return SavedChart::class; }

    public function scope(?Authenticatable $user): ?string
    {
        return $user ? 'user:'.$user->getAuthIdentifier() : null;
    }

    public function rules(): array
    {
        return ['name' => ['required', 'string', 'max:255'], 'year' => ['required', 'integer']];
    }
}
```

Content devices only read: override `writable()` to return `false` and set `scope()` to `'global'`.
For tables whose payload is plain columns, add `protected bool $bulk = true;` (see Efficiency).
A custom `SyncCollection` gets each push as one `apply($scope, $changes)` call.
Schedule `osync:prune` daily.

Mass `update()`/`delete()` on the query builder fire no model events and are **not** journalled. Change records through models, or call `Journal::recordServerChange()`.

## Tests

```sh
composer install && vendor/bin/pest                     # 27 tests
vendor/bin/pest --group=bench                           # the numbers above
```

`workbench/` is a throwaway server for osync-dart's end-to-end tests: the bearer token is the user's name, and two routes edit records the way a website would.

```sh
touch workbench/e2e.sqlite && vendor/bin/testbench serve --port=8787
```

## Using it in AstroBaje

It can replace both hand-rolled syncs:

- **`SyncService` + `SyncController` (kundalis, matches).** Those push the whole local set every time and pull everything back. As `charts`/`matches` collections, a sync sends only the outbox and pulls only past the cursor. Website-made charts arrive through `Syncable` instead of the `web-<id>` back-fill.
- **`ContentSyncService` (mantras, sapanafal, home layout, rashifal).** It downloads whole versioned snapshots. As read-only `global` collections, one edited mantra syncs as one change.

Migration steps: add `sync_scope`/`sync_id` columns (back-filled from `user_id`/`client_id`), keep the old `/sync` endpoint until the app version using this package is the minimum supported one, and on first launch seed the outbox from the existing local Isar data.
