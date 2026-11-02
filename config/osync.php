<?php

return [
    // The collections devices may sync: classes implementing
    // Nepicsoft\Osync\Contracts\SyncCollection.
    'collections' => [],

    'route' => [
        'enabled' => true,
        'uri' => 'api/sync',
        'middleware' => ['api', 'auth:sanctum'],
    ],

    // Gzip request and response bodies on the sync route. Turn off only when
    // the web server already compresses application/json both ways.
    'compression' => true,

    // Database connection for the journal; null uses the default.
    'connection' => null,

    // Changes returned per collection per request; clients page with has_more.
    'page_size' => 500,

    // Changes a client may push per collection per request.
    'max_push' => 500,

    // Deletes are remembered this long. A device offline for longer resyncs
    // its collections from scratch. Run `osync:prune` daily.
    'tombstone_retention_days' => 90,

    // Node id stamped on changes made on the server.
    'node' => 'server',
];
