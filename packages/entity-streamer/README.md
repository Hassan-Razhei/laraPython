# Entity Streamer

> High-performance RFC 7233 byte-range HTTP 206 video & audio streaming engine for Laravel.

[![Tests](https://img.shields.io/badge/tests-77%20passed-brightgreen.svg)](#)
[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](LICENSE)
[![PHP](https://img.shields.io/badge/PHP-8.2%20%7C%208.3%20%7C%208.4-777bb4.svg)](#)
[![Laravel](https://img.shields.io/badge/Laravel-11.x%20%7C%2012.x-ff2d20.svg)](#)

---

## 🌟 Overview

`entity-streamer` is a memory-safe binary streaming package designed to serve multi-gigabyte video and audio files in Laravel applications. It strictly complies with **RFC 7233 (Range Requests)**, enabling seamless seeking, fast scrubbing, and bandwidth optimization in HTML5 players (`<video>`, `<audio>`), Mobile Apps, and desktop browsers.

---

## 🚀 Key Features

- **Strict RFC 7233 Engine**: Full support for closed (`0-1024`), open-ended (`1048576-`), and suffix ranges (`-500`), returning proper HTTP `206 Partial Content` or `416 Range Not Satisfiable`.
- **Memory-Safe Kernel**: Streams data in configurable chunks (default: 64 KB) without loading large media into server RAM.
- **Storage Agnostic**: Native integration with Laravel Storage (Local, S3, MinIO) with automatic fallback path lookup.
- **Format Intelligence**: Automatic MIME detection via binary magic bytes for all major video (MP4, WebM, MKV, QuickTime, AVI) and audio formats (MP3, WAV, AAC, FLAC, OGG, M4A).
- **HTTP Cache Optimization**: Automatic `ETag` and `Last-Modified` generation with `304 Not Modified` validation to conserve bandwidth.
- **Security Shield**: Multi-layer sanitization against path traversal (`..`, `%252e%252e`, null bytes, system devices, and hidden files).
- **Signed URLs & Anti-Leech**: HMAC-SHA256 time-limited streaming URLs with optional client IP binding to prevent hotlinking.
- **Eloquent Integration**: `HasStreamableMedia` trait for one-line media streaming directly from models.
- **Developer Experience**: Fluent builder, `Streamer` facade, and `response()->streamMedia()` macro.

---

## 📥 Installation

Require the package via Composer:

```bash
composer require hassan-razhei/entity-streamer
```

Publish the configuration file (optional):

```bash
php artisan vendor:publish --tag=streamer-config
```

---

## 🛠 Usage Examples

### 1. Using the Facade

```php
use EntityStreamer\Facades\Streamer;

Route::get('/watch/{video}', function (string $video, Request $request) {
    return Streamer::disk('s3')
        ->withChunkSize(64 * 1024)
        ->asInline("lesson.mp4")
        ->toResponse($request);
});
```

### 2. Using the Response Macro

```php
Route::get('/listen/{surah}', function (string $surah) {
    return response()->streamMedia("audio/{$surah}.mp3", disk: 'public');
});
```

### 3. Eloquent Model Integration

Implement `CanStreamMedia` and use `HasStreamableMedia` trait on your model:

```php
namespace App\Models;

use EntityStreamer\Contracts\CanStreamMedia;
use EntityStreamer\Traits\HasStreamableMedia;
use Illuminate\Database\Eloquent\Model;

class Lesson extends Model implements CanStreamMedia
{
    use HasStreamableMedia;

    protected $fillable = ['title', 'video_path'];
}
```

Stream directly inside your controller:

```php
public function stream(Lesson $lesson, Request $request)
{
    return $lesson->streamResponse($request);
}
```

Generate signed temporary streaming URLs with Anti-Leech protection:

```php
// Signed for 1 hour, bound to the current user's IP
$url = $lesson->getStreamUrl(expiration: 3600, bindToIp: true);
```

### 4. Forcing Download (Attachment Mode)

```php
return Streamer::stream('exports/recorded_session.mp4')
    ->asAttachment('lecture_archive.mp4')
    ->toResponse($request);
```

---

## 🧪 Testing

Run the test suite:

```bash
php packages/entity-streamer/tests/run_tests.php
```

All 77 test cases cover:
- RFC 7233 partial, open, and suffix ranges.
- Memory bounding and zero-ballooning during 2GB+ streaming.
- Forward and backward bidirectional seeking accuracy.
- Directory traversal fuzzing and null-byte defenses.
- HMAC-SHA256 signature verification and Anti-Leech protection.
- HTML5 media player lifecycle simulations.

---

## 📄 License

This package is open-sourced software licensed under the [MIT license](LICENSE).

