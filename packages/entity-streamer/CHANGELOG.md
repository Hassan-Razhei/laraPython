# Changelog

All notable changes to `entity-streamer` will be documented in this file.

## [1.0.0] - 2026-11-25

### Initial Release
- **Byte-Range Protocol Kernel**: RFC 7233 compliant parser with support for closed, open, and suffix byte ranges.
- **BinaryStreamer**: Memory-safe chunked binary streamer with buffer flushing and client disconnect detection.
- **Storage Agnosticism**: Multi-disk Flysystem adapter supporting local and cloud storage with fallback directories.
- **MIME & Cache Intelligence**: Magic bytes detection for all video/audio formats and HTTP 304 conditional cache validation.
- **Security Shield**: Multi-layer path sanitization and HMAC-SHA256 time-limited signed URLs with Anti-Leech protection.
- **Eloquent Integration**: `HasStreamableMedia` trait and `CanStreamMedia` interface.
- **Developer Experience**: `Streamer` facade, fluent builder, config file, and `Response::macro('streamMedia')`.
- **Enterprise Test Suite**: 77 tests with 100% pass rate.

