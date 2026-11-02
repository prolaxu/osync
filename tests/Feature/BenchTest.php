<?php

// Numbers, not checks: vendor/bin/pest --group=bench

use Illuminate\Support\Facades\DB;
use Prolaxu\Osync\CollectionRegistry;
use Prolaxu\Osync\Tests\Fixtures\BulkNoteCollection;
use Prolaxu\Osync\Tests\Fixtures\User;

it('bench', function () {
    $user = User::create(['name' => 'bench']);
    $changes = array_map(fn (int $i) => ['id' => "n{$i}", 'op' => 'upsert', 'hlc' => sprintf('%015d:%05d:phone', 1000 + $i, 0), 'data' => ['title' => "Note {$i}", 'body' => str_repeat('x', 40)]], range(1, 500));

    DB::enableQueryLog();
    $t = microtime(true);
    $push = $this->actingAs($user)->postJson('/api/sync', ['protocol' => 1, 'collections' => ['notes' => ['cursor' => 0, 'changes' => $changes]], 'node' => 'phone']);
    $pushMs = (microtime(true) - $t) * 1000;
    $pushQueries = count(DB::getQueryLog());
    $pushBytes = strlen($push->getContent());

    DB::flushQueryLog();
    $t = microtime(true);
    $pull = $this->actingAs($user)->postJson('/api/sync', ['protocol' => 1, 'collections' => ['notes' => ['cursor' => 0]]]);
    $pullMs = (microtime(true) - $t) * 1000;
    $pullQueries = count(DB::getQueryLog());
    fwrite(STDERR, "\nBENCH pull500 gzip: ".strlen(gzencode($pull->getContent(), 6))." bytes\n");

    DB::flushQueryLog();
    $idle = $this->actingAs($user)->postJson('/api/sync', ['protocol' => 1, 'collections' => ['notes' => ['cursor' => $pull->json('collections.notes.cursor')]]]);
    $idleQueries = count(DB::getQueryLog());

    fwrite(STDERR, sprintf("\nBENCH push500: %d queries %.0fms resp %d bytes (echoed %d) | pull500: %d queries %.0fms %d bytes | idle: %d queries\n",
        $pushQueries, $pushMs, $pushBytes, count($push->json('collections.notes.changes')), $pullQueries, $pullMs, strlen($pull->getContent()), $idleQueries));
    expect(true)->toBeTrue();
})->group('bench');

it('bench bulk', function () {
    config(['osync.collections' => [BulkNoteCollection::class]]);
    app()->forgetInstance(CollectionRegistry::class);
    $user = User::create(['name' => 'bench']);
    $changes = array_map(fn (int $i) => ['id' => "n{$i}", 'op' => 'upsert', 'hlc' => sprintf('%015d:%05d:phone', 1000 + $i, 0), 'data' => ['title' => "Note {$i}", 'body' => str_repeat('x', 40)]], range(1, 500));
    DB::enableQueryLog();
    $t = microtime(true);
    $this->actingAs($user)->postJson('/api/sync', ['protocol' => 1, 'node' => 'phone', 'collections' => ['notes' => ['cursor' => 0, 'changes' => $changes]]])->assertOk();
    fwrite(STDERR, sprintf("\nBENCH bulk push500: %d queries %.0fms\n", count(DB::getQueryLog()), (microtime(true) - $t) * 1000));
    expect(true)->toBeTrue();
})->group('bench');
