<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Default Storage Disk
    |--------------------------------------------------------------------------
    | The default storage disk used to locate and stream media files.
    | When null, direct filesystem paths and Laravel default disks are used.
    */
    'default_disk' => env('STREAMER_DISK', null),

    /*
    |--------------------------------------------------------------------------
    | Buffer Chunk Size
    |--------------------------------------------------------------------------
    | The buffer size (in bytes) used to stream media content in chunks.
    | Default: 65,536 bytes (64 KB).
    */
    'chunk_size' => (int) env('STREAMER_CHUNK_SIZE', 64 * 1024),

    /*
    |--------------------------------------------------------------------------
    | Bandwidth Throttling (Rate Shaping)
    |--------------------------------------------------------------------------
    | Maximum transmission rate in Kilobytes per second (KB/s).
    | When set to null, streaming will transfer at maximum available speed.
    */
    'throttle_kbps' => env('STREAMER_THROTTLE_KBPS') ? (int) env('STREAMER_THROTTLE_KBPS') : null,

    /*
    |--------------------------------------------------------------------------
    | Automatic Fallback Directories
    |--------------------------------------------------------------------------
    | Prefixes to check if a media file cannot be found directly in the root path.
    */
    'fallback_prefixes' => [
        'videos/',
        'imports/',
        'audio/',
    ],

    /*
    |--------------------------------------------------------------------------
    | HTTP Cache Control
    |--------------------------------------------------------------------------
    | Headers applied to media responses to facilitate browser and CDN caching.
    */
    'cache' => [
        'control' => env('STREAMER_CACHE_CONTROL', 'public, max-age=86400, no-transform'),
        'weak_etag' => (bool) env('STREAMER_WEAK_ETAG', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Signed Streaming URLs Security
    |--------------------------------------------------------------------------
    | Configuration for time-limited signed streaming links and Anti-Leech.
    */
    'security' => [
        'signature_ttl' => (int) env('STREAMER_SIGNATURE_TTL', 3600),
        'bind_to_ip' => (bool) env('STREAMER_BIND_IP', false),
    ],
];
