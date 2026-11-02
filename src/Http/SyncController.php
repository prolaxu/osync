<?php

namespace Nepicsoft\Osync\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Nepicsoft\Osync\Data\CollectionResult;
use Nepicsoft\Osync\Exceptions\ScopeDenied;
use Nepicsoft\Osync\Exceptions\UnknownCollection;
use Nepicsoft\Osync\SyncEngine;
use Nepicsoft\Osync\SyncProtocol;

final class SyncController
{
    public function __invoke(SyncRequest $request, SyncEngine $engine): JsonResponse
    {
        try {
            $results = $engine->sync($request->user(), $request->pushes(), $request->node());
        } catch (UnknownCollection $e) {
            return response()->json(['message' => $e->getMessage(), 'collection' => $e->collection], 422);
        } catch (ScopeDenied $e) {
            return response()->json(['message' => $e->getMessage(), 'collection' => $e->collection], 403);
        }

        return response()->json([
            'protocol' => SyncProtocol::VERSION,
            'server_time' => Carbon::now()->getTimestampMs(),
            'collections' => array_map(fn (CollectionResult $r): array => $r->toArray(), $results),
        ]);
    }
}
