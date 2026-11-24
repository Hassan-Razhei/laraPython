<?php

declare(strict_types=1);

namespace EntityStreamer\Facades;

use EntityStreamer\StreamerManager;
use Illuminate\Support\Facades\Facade;

/**
 * @method static \EntityStreamer\StreamerBuilder stream(string $path)
 * @method static \EntityStreamer\StreamerBuilder disk(?string $disk)
 * @method static \EntityStreamer\StreamerBuilder withChunkSize(int $bytes)
 * @method static \EntityStreamer\StreamerBuilder withThrottle(?int $kbps)
 * @method static \EntityStreamer\StreamerBuilder withFallbacks(array $prefixes)
 * @method static \EntityStreamer\StreamerBuilder asInline(?string $filename = null)
 * @method static \EntityStreamer\StreamerBuilder asAttachment(?string $filename = null)
 * @method static string sign(string $path, mixed $expiration = null, array $parameters = [], bool $bindToIp = false)
 * @method static bool validateSignature(\Symfony\Component\HttpFoundation\Request $request, string $path)
 * @method static \EntityStreamer\Storage\StreamableFile resolveFile(string $path, ?string $disk = null)
 *
 * @see StreamerManager
 */
class Streamer extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'entity-streamer';
    }
}
