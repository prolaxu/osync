<?php

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Prolaxu\Osync\CollectionRegistry;
use Prolaxu\Osync\Tests\Fixtures\BulkNoteCollection;
use Prolaxu\Osync\Tests\Fixtures\Card;
use Prolaxu\Osync\Tests\Fixtures\Mantra;
use Prolaxu\Osync\Tests\Fixtures\Note;
use Prolaxu\Osync\Tests\Fixtures\User;

/** @param array<string, array<string, mixed>> $collections */
function syncAs(User $user, array $collections): TestResponse
{
    return test()->actingAs($user)->postJson('/api/sync', ['protocol' => 1, 'collections' => $collections]);
}

function stamp(int $millis, string $node = 'phone-a', int $counter = 0): string
{
    return sprintf('%015d:%05d:%s', $millis, $counter, $node);
}

function upsert(string $id, array $data, string $hlc): array
{
    return ['id' => $id, 'op' => 'upsert', 'data' => $data, 'hlc' => $hlc];
}

beforeEach(function () {
    $this->user = User::create(['name' => 'Ram']);
});

it('stores a pushed record and hands it back with a cursor', function () {
    $response = syncAs($this->user, ['notes' => [
        'cursor' => 0,
        'changes' => [upsert('n1', ['title' => 'Kundali notes'], stamp(1000))],
    ]])->assertOk();

    expect($response->json('collections.notes'))
        ->cursor->toBe(1)
        ->reset->toBeFalse()
        ->has_more->toBeFalse()
        ->not->toHaveKey('rejected')
        ->and($response->json('collections.notes.changes.0'))->toMatchArray([
            'id' => 'n1', 'op' => 'upsert', 'hlc' => stamp(1000), 'data' => ['title' => 'Kundali notes'],
        ])
        ->and(Note::where('sync_id', 'n1')->value('title'))->toBe('Kundali notes')
        ->and(Note::value('sync_scope'))->toBe('user:'.$this->user->id);
});

it('gives a second device everything from zero and nothing past its cursor', function () {
    syncAs($this->user, ['notes' => ['changes' => [
        upsert('n1', ['title' => 'One'], stamp(1000)),
        upsert('n2', ['title' => 'Two'], stamp(1001)),
    ]]]);

    $fresh = syncAs($this->user, ['notes' => ['cursor' => 0]]);
    expect(array_column($fresh->json('collections.notes.changes'), 'id'))->toBe(['n1', 'n2']);

    $caughtUp = syncAs($this->user, ['notes' => ['cursor' => $fresh->json('collections.notes.cursor')]]);
    expect($caughtUp->json('collections.notes.changes'))->toBe([])
        ->and($caughtUp->json('collections.notes.cursor'))->toBe($fresh->json('collections.notes.cursor'));
});

it('lets the newer write win and returns the winner to the loser', function () {
    syncAs($this->user, ['notes' => ['changes' => [upsert('n1', ['title' => 'From phone B'], stamp(2000, 'phone-b'))]]]);

    $response = syncAs($this->user, ['notes' => ['cursor' => 1, 'changes' => [
        upsert('n1', ['title' => 'Older edit from A'], stamp(1500)),
    ]]]);

    expect($response->json('collections.notes.rejected.0'))->toMatchArray([
        'id' => 'n1',
        'reason' => 'stale',
        'current' => ['id' => 'n1', 'op' => 'upsert', 'hlc' => stamp(2000, 'phone-b'), 'data' => ['title' => 'From phone B']],
    ])->and(Note::where('sync_id', 'n1')->value('title'))->toBe('From phone B');

    syncAs($this->user, ['notes' => ['changes' => [upsert('n1', ['title' => 'Newest from A'], stamp(2500))]]]);
    expect(Note::where('sync_id', 'n1')->value('title'))->toBe('Newest from A');
});

it('treats a resent change as already applied', function () {
    $change = upsert('n1', ['title' => 'Once'], stamp(1000));
    syncAs($this->user, ['notes' => ['changes' => [$change]]]);

    $retry = syncAs($this->user, ['notes' => ['cursor' => 1, 'changes' => [$change]]]);

    expect($retry->json('collections.notes'))
        ->not->toHaveKey('rejected')
        ->changes->toBe([])
        ->cursor->toBe(1);
});

it('syncs a delete as a tombstone and lets a newer write bring the record back', function () {
    syncAs($this->user, ['notes' => ['changes' => [upsert('n1', ['title' => 'Temp'], stamp(1000))]]]);
    $deleted = syncAs($this->user, ['notes' => ['cursor' => 1, 'changes' => [
        ['id' => 'n1', 'op' => 'delete', 'hlc' => stamp(1100)],
    ]]]);

    expect($deleted->json('collections.notes.changes.0'))->toBe(['id' => 'n1', 'op' => 'delete', 'hlc' => stamp(1100)])
        ->and(Note::count())->toBe(0)
        ->and(Note::withTrashed()->count())->toBe(1);

    syncAs($this->user, ['notes' => ['changes' => [upsert('n1', ['title' => 'Back'], stamp(1200))]]]);
    expect(Note::where('sync_id', 'n1')->value('title'))->toBe('Back')
        ->and(Note::withTrashed()->count())->toBe(1);
});

it('rejects invalid data with its errors and writes nothing', function () {
    $response = syncAs($this->user, ['notes' => ['changes' => [upsert('n1', ['title' => ''], stamp(1000))]]]);

    expect($response->json('collections.notes.rejected.0'))
        ->reason->toBe('invalid')
        ->errors->toHaveKey('title')
        ->and(Note::count())->toBe(0)
        ->and($response->json('collections.notes.changes'))->toBe([]);
});

it('keeps only validated fields', function () {
    syncAs($this->user, ['notes' => ['changes' => [upsert('n1', ['title' => 'Ok', 'sync_scope' => 'user:999'], stamp(1000))]]]);

    expect(Note::value('sync_scope'))->toBe('user:'.$this->user->id);
});

it('publishes server edits to devices and refuses device writes to read-only content', function () {
    Mantra::create(['text' => 'Om Namah Shivaya']);

    $response = syncAs($this->user, ['mantras' => ['cursor' => 0, 'changes' => [
        upsert('hacked', ['text' => 'nope'], stamp(1000)),
    ]]]);

    expect($response->json('collections.mantras.rejected.0.reason'))->toBe('read_only')
        ->and(array_column($response->json('collections.mantras.changes'), 'data'))->toBe([['text' => 'Om Namah Shivaya']])
        ->and(Mantra::count())->toBe(1);
});

it('stamps a server edit to beat whatever the record held, even from a fast phone clock', function () {
    $farFuture = stamp(Carbon::now()->addYear()->getTimestampMs());
    syncAs($this->user, ['notes' => ['changes' => [upsert('n1', ['title' => 'Phone'], $farFuture)]]]);

    Note::where('sync_id', 'n1')->first()->update(['title' => 'Edited on the website']);

    $pulled = syncAs($this->user, ['notes' => ['cursor' => 1]]);
    expect($pulled->json('collections.notes.changes.0'))
        ->data->toBe(['title' => 'Edited on the website', 'body' => null])
        ->and(strcmp($pulled->json('collections.notes.changes.0.hlc'), $farFuture))->toBeGreaterThan(0);
});

it('keeps each user to their own scope', function () {
    syncAs($this->user, ['notes' => ['changes' => [upsert('n1', ['title' => 'Private'], stamp(1000))]]]);
    $other = User::create(['name' => 'Sita']);

    expect(syncAs($other, ['notes' => ['cursor' => 0]])->json('collections.notes.changes'))->toBe([]);

    syncAs($other, ['notes' => ['changes' => [upsert('n1', ['title' => 'Same id, other user'], stamp(500))]]]);
    expect(Note::where('sync_scope', 'user:'.$this->user->id)->value('title'))->toBe('Private')
        ->and(Note::count())->toBe(2);
});

it('pages a large pull', function () {
    config(['osync.page_size' => 2]);
    syncAs($this->user, ['notes' => ['changes' => array_map(
        fn (int $i) => upsert("n{$i}", ['title' => "Note {$i}"], stamp(1000 + $i)),
        range(1, 5),
    )]]);

    $ids = [];
    $cursor = 0;
    $pages = 0;
    do {
        $page = syncAs($this->user, ['notes' => ['cursor' => $cursor]])->json('collections.notes');
        $ids = [...$ids, ...array_column($page['changes'], 'id')];
        $cursor = $page['cursor'];
        $pages++;
    } while ($page['has_more']);

    expect($ids)->toBe(['n1', 'n2', 'n3', 'n4', 'n5'])->and($pages)->toBe(3);
});

it('collapses a record to its latest change', function () {
    foreach (range(1, 3) as $i) {
        syncAs($this->user, ['notes' => ['changes' => [upsert('n1', ['title' => "v{$i}"], stamp(1000 + $i))]]]);
    }

    $pull = syncAs($this->user, ['notes' => ['cursor' => 0]])->json('collections.notes');
    expect($pull['changes'])->toHaveCount(1)
        ->and($pull['changes'][0]['data']['title'])->toBe('v3')
        ->and($pull['cursor'])->toBe(3);
});

it('tells a device offline past the tombstone window to reset', function () {
    syncAs($this->user, ['notes' => ['changes' => [
        upsert('keep', ['title' => 'Keep'], stamp(1000)),
        upsert('gone', ['title' => 'Gone'], stamp(1001)),
    ]]]);
    $staleCursor = 2;
    syncAs($this->user, ['notes' => ['changes' => [['id' => 'gone', 'op' => 'delete', 'hlc' => stamp(1002)]]]]);

    Carbon::setTestNow(Carbon::now()->addDays(91));
    $this->artisan('osync:prune')->assertSuccessful();

    $pull = syncAs($this->user, ['notes' => ['cursor' => $staleCursor]])->json('collections.notes');
    expect($pull['reset'])->toBeTrue()
        ->and(array_column($pull['changes'], 'id'))->toBe(['keep']);

    Carbon::setTestNow();
});

it('answers unknown collections, bad envelopes and guests', function () {
    syncAs($this->user, ['ghosts' => ['cursor' => 0]])->assertStatus(422)->assertJson(['collection' => 'ghosts']);

    $this->actingAs($this->user)->postJson('/api/sync', ['protocol' => 9, 'collections' => ['notes' => []]])
        ->assertStatus(422)->assertJsonValidationErrors('protocol');

    syncAs($this->user, ['notes' => ['changes' => [['id' => 'x', 'op' => 'upsert', 'hlc' => 'yesterday']]]])
        ->assertStatus(422);

    auth()->logout();
    $this->postJson('/api/sync', ['protocol' => 1, 'collections' => ['notes' => []]])->assertUnauthorized();
});

it('leaves a device\'s own changes out of its pulls', function () {
    $push = fn (int $cursor, array $changes = []) => $this->actingAs($this->user)->postJson('/api/sync', [
        'protocol' => 1,
        'node' => 'phone-a',
        'collections' => ['notes' => ['cursor' => $cursor, 'changes' => $changes]],
    ])->json('collections.notes');

    $push(0, [upsert('base', ['title' => 'Base'], stamp(900, 'phone-b'))]);
    $first = $push(1, [upsert('mine', ['title' => 'Mine'], stamp(1000))]);
    expect($first['changes'])->toBe([])->and($first['cursor'])->toBe(2);

    syncAs($this->user, ['notes' => ['changes' => [upsert('theirs', ['title' => 'Theirs'], stamp(1100, 'phone-b'))]]]);
    expect(array_column($push(2)['changes'], 'id'))->toBe(['theirs'])
        ->and(array_column($push(0)['changes'], 'id'))->toBe(['base', 'theirs']);
});

it('sends a device its own changes back after a reset', function () {
    $sync = fn (int $cursor, array $changes = []) => $this->actingAs($this->user)->postJson('/api/sync', [
        'protocol' => 1,
        'node' => 'phone-a',
        'collections' => ['notes' => ['cursor' => $cursor, 'changes' => $changes]],
    ])->json('collections.notes');

    $sync(0, [upsert('mine', ['title' => 'Mine'], stamp(1000)), upsert('gone', ['title' => 'Gone'], stamp(1001))]);
    $sync(2, [['id' => 'gone', 'op' => 'delete', 'hlc' => stamp(1002)]]);
    Carbon::setTestNow(Carbon::now()->addDays(91));
    $this->artisan('osync:prune')->assertSuccessful();

    $pull = $sync(1);
    expect($pull['reset'])->toBeTrue()->and(array_column($pull['changes'], 'id'))->toBe(['mine']);
    Carbon::setTestNow();
});

it('commits a pushed batch in a fixed number of journal queries', function () {
    $changes = fn (int $n, int $from) => array_map(
        fn (int $i) => upsert("n{$i}", ['title' => "Note {$i}"], stamp($from + $i)),
        range(1, $n),
    );

    $count = function (array $batch): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        syncAs($this->user, ['notes' => ['changes' => $batch]]);
        $journal = collect(DB::getQueryLog())->filter(fn (array $q) => str_contains($q['query'], 'osync_'))->count();
        DB::disableQueryLog();

        return $journal;
    };

    expect($count($changes(10, 1000)))->toBe($count($changes(200, 5000)));
});

it('writes a batch in bulk when the collection asks for it', function () {
    config(['osync.collections' => [BulkNoteCollection::class]]);
    app()->forgetInstance(CollectionRegistry::class);

    syncAs($this->user, ['notes' => ['changes' => [
        upsert('a', ['title' => 'A', 'body' => 'one'], stamp(1000)),
        upsert('b', ['title' => 'B'], stamp(1001)),
    ]]])->assertOk();
    syncAs($this->user, ['notes' => ['cursor' => 2, 'changes' => [
        upsert('a', ['title' => 'A2'], stamp(1100)),
        ['id' => 'b', 'op' => 'delete', 'hlc' => stamp(1101)],
    ]]])->assertOk();

    expect(Note::where('sync_id', 'a')->first())
        ->title->toBe('A2')
        ->body->toBe('one')
        ->and(Note::where('sync_id', 'b')->exists())->toBeFalse()
        ->and(Note::withTrashed()->where('sync_id', 'b')->exists())->toBeTrue();

    syncAs($this->user, ['notes' => ['cursor' => 4, 'changes' => [upsert('b', ['title' => 'B back'], stamp(1200))]]]);
    expect(Note::where('sync_id', 'b')->value('title'))->toBe('B back');
});

it('takes gzipped requests and gzips large responses', function () {
    $changes = array_map(fn (int $i) => upsert("n{$i}", ['title' => "Note {$i}"], stamp(1000 + $i)), range(1, 40));
    $body = gzencode(json_encode(['protocol' => 1, 'collections' => ['notes' => ['cursor' => 0, 'changes' => $changes]]]));

    $this->actingAs($this->user)->call('POST', '/api/sync', server: [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_CONTENT_ENCODING' => 'gzip',
        'HTTP_ACCEPT' => 'application/json',
    ], content: $body)->assertOk();
    expect(Note::count())->toBe(40);

    $pull = $this->actingAs($this->user)->call('POST', '/api/sync', server: [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_ACCEPT_ENCODING' => 'gzip, deflate',
    ], content: json_encode(['protocol' => 1, 'collections' => ['notes' => ['cursor' => 0]]]));

    $plain = gzdecode($pull->getContent());
    expect($pull->headers->get('Content-Encoding'))->toBe('gzip')
        ->and(strlen($pull->getContent()))->toBeLessThan(strlen($plain) / 3)
        ->and(json_decode($plain, true)['collections']['notes']['changes'])->toHaveCount(40);
});

it('refuses a gzip body that is not gzip', function () {
    $this->actingAs($this->user)->call('POST', '/api/sync', server: [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_CONTENT_ENCODING' => 'gzip',
        'HTTP_ACCEPT' => 'application/json',
    ], content: 'not gzip')->assertStatus(400);
});

it('sends back what the server stored when it differs from the push, to the sender too', function () {
    $sync = fn (int $cursor, array $changes = []) => $this->actingAs($this->user)->postJson('/api/sync', [
        'protocol' => 1,
        'node' => 'phone-a',
        'collections' => ['notes' => ['cursor' => $cursor, 'changes' => $changes]],
    ])->json('collections.notes');

    $result = $sync(0, [upsert('n1', ['title' => '  Spaced   out  '], stamp(1000))]);

    expect(Note::value('title'))->toBe('Spaced out')
        ->and($result['changes'])->toHaveCount(1)
        ->and($result['changes'][0]['data']['title'])->toBe('Spaced out')
        ->and($result['changes'][0]['hlc'])->toEndWith(':server')
        ->and(strcmp($result['changes'][0]['hlc'], stamp(1000)))->toBeGreaterThan(0);

    $clean = $sync($result['cursor'], [upsert('n2', ['title' => 'Already clean'], stamp(1100))]);
    expect($clean['changes'])->toBe([]);
});

it('syncs a model owned through user_id, and skips saves devices cannot see', function () {
    syncAs($this->user, ['cards' => ['changes' => [upsert('c1', ['label' => 'Mine'], stamp(1000))]]])->assertOk();
    $card = Card::where('client_id', 'c1')->first();
    expect($card->user_id)->toBe($this->user->id);

    $card->update(['cache' => 'recomputed']);
    expect(syncAs($this->user, ['cards' => ['cursor' => 1]])->json('collections.cards.changes'))->toBe([]);

    $card->update(['label' => 'Renamed on the web']);
    expect(syncAs($this->user, ['cards' => ['cursor' => 1]])->json('collections.cards.changes.0.data'))->toBe(['label' => 'Renamed on the web']);

    $other = User::create(['name' => 'Sita']);
    expect(syncAs($other, ['cards' => ['cursor' => 0]])->json('collections.cards.changes'))->toBe([]);
});
