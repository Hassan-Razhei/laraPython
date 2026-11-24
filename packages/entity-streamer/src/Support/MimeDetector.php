<?php

declare(strict_types=1);

namespace EntityStreamer\Support;

/**
 * High-precision Audio & Video MIME-Type Detector.
 *
 * Employs a multi-layered inspection strategy:
 * 1. Binary Magic Bytes & File Signatures (Tamper-proof).
 * 2. PHP Fileinfo extension (if available).
 * 3. Comprehensive Extension-to-MIME Mapping table.
 */
class MimeDetector
{
    /** Comprehensive mapping table for all known Audio & Video extensions */
    public const EXTENSION_MAP = [
        // --- Video Formats ---
        'mp4' => 'video/mp4',
        'm4v' => 'video/mp4',
        'f4v' => 'video/mp4',
        'webm' => 'video/webm',
        'mkv' => 'video/x-matroska',
        'mov' => 'video/quicktime',
        'qt' => 'video/quicktime',
        'avi' => 'video/x-msvideo',
        'wmv' => 'video/x-ms-wmv',
        'asf' => 'video/x-ms-asf',
        'flv' => 'video/x-flv',
        'ogv' => 'video/ogg',
        'ts' => 'video/mp2t',
        'm2ts' => 'video/mp2t',
        'mts' => 'video/mp2t',
        '3gp' => 'video/3gpp',
        '3g2' => 'video/3gpp2',
        'mpeg' => 'video/mpeg',
        'mpg' => 'video/mpeg',
        'mpe' => 'video/mpeg',
        'm1v' => 'video/mpeg',
        'm2v' => 'video/mpeg',
        'vob' => 'video/x-ms-vob',

        // --- Audio Formats ---
        'mp3' => 'audio/mpeg',
        'wav' => 'audio/wav',
        'wave' => 'audio/wav',
        'm4a' => 'audio/mp4',
        'aac' => 'audio/aac',
        'flac' => 'audio/flac',
        'oga' => 'audio/ogg',
        'ogg' => 'audio/ogg',
        'opus' => 'audio/opus',
        'weba' => 'audio/webm',
        'wma' => 'audio/x-ms-wma',
        'aif' => 'audio/x-aiff',
        'aiff' => 'audio/x-aiff',
        'aifc' => 'audio/x-aiff',
        'mid' => 'audio/midi',
        'midi' => 'audio/midi',
        'kar' => 'audio/midi',
        'amr' => 'audio/amr',
        'alac' => 'audio/alac',
        'ac3' => 'audio/ac3',
        'eac3' => 'audio/eac3',
    ];

    /**
     * Detect the MIME type of a media asset using binary magic bytes with extension fallback.
     *
     * @param string $pathOrExtension File path or extension
     * @param string|resource|null $contentSample First 64-128 bytes sample or stream resource
     * @param string|null $default Fallback MIME type if undetermined
     * @return string
     */
    public function detect(
        string $pathOrExtension,
        mixed $contentSample = null,
        ?string $default = 'application/octet-stream'
    ): string {
        $sample = $this->extractSample($contentSample);

        // 1. Inspect binary magic bytes if sample is available
        if ($sample !== null && strlen($sample) >= 4) {
            $magicMime = $this->detectFromMagicBytes($sample, $pathOrExtension);
            if ($magicMime !== null) {
                return $magicMime;
            }
        }

        // 2. Fallback to extension lookup
        $extension = $this->extractExtension($pathOrExtension);
        if ($extension !== '' && isset(self::EXTENSION_MAP[$extension])) {
            return self::EXTENSION_MAP[$extension];
        }

        // 3. Fallback to PHP native finfo if available
        if ($sample !== null && function_exists('finfo_open')) {
            $finfo = @finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo !== false) {
                $mime = @finfo_buffer($finfo, $sample);
                @finfo_close($finfo);
                if ($mime && $mime !== 'application/octet-stream' && $mime !== 'text/plain') {
                    return $mime;
                }
            }
        }

        // 4. Heuristic default based on category
        if ($this->isVideoExtension($extension)) {
            return 'video/mp4';
        }

        if ($this->isAudioExtension($extension)) {
            return 'audio/mpeg';
        }

        return $default ?? 'application/octet-stream';
    }

    /**
     * Detect MIME type strictly from binary magic byte signatures.
     */
    public function detectFromMagicBytes(string $sample, string $hintPath = ''): ?string
    {
        $len = strlen($sample);
        $ext = $this->extractExtension($hintPath);

        // --- 1. MP3 / ID3 Audio ---
        if (str_starts_with($sample, 'ID3')) {
            return $ext === 'aac' ? 'audio/aac' : 'audio/mpeg';
        }
        if ($len >= 2 && ord($sample[0]) === 0xFF && (ord($sample[1]) & 0xE0) === 0xE0) {
            // MPEG audio frame sync bits
            if ((ord($sample[1]) & 0x06) === 0x00) {
                // Layer-less or AAC ADTS
                return 'audio/aac';
            }
            return 'audio/mpeg';
        }

        // --- 2. FLAC Audio ---
        if (str_starts_with($sample, 'fLaC')) {
            return 'audio/flac';
        }

        // --- 3. RIFF Containers (WAV / AVI) ---
        if (str_starts_with($sample, 'RIFF') && $len >= 12) {
            $format = substr($sample, 8, 4);
            if ($format === 'WAVE') {
                return 'audio/wav';
            }
            if ($format === 'AVI ') {
                return 'video/x-msvideo';
            }
        }

        // --- 4. EBML Containers (Matroska / WebM) ---
        if (str_starts_with($sample, "\x1A\x45\xDF\xA3")) {
            if (str_contains($sample, 'matroska')) {
                return 'video/x-matroska';
            }
            if (str_contains($sample, 'webm') || $ext === 'webm') {
                return $ext === 'weba' ? 'audio/webm' : 'video/webm';
            }
            return $ext === 'weba' ? 'audio/webm' : 'video/webm';
        }

        // --- 5. Ogg Containers (Vorbis / Opus / Theora) ---
        if (str_starts_with($sample, 'OggS')) {
            if (str_contains($sample, 'OpusHead') || $ext === 'opus') {
                return 'audio/opus';
            }
            if ($ext === 'ogv') {
                return 'video/ogg';
            }
            return 'audio/ogg';
        }

        // --- 6. ISO Base Media File Format (MP4, QuickTime, M4A, 3GP) ---
        if ($len >= 12 && substr($sample, 4, 4) === 'ftyp') {
            $majorBrand = substr($sample, 8, 4);

            if (str_starts_with($majorBrand, 'M4A') || str_starts_with($majorBrand, 'alac') || $ext === 'm4a') {
                return 'audio/mp4';
            }
            if (str_starts_with($majorBrand, 'qt') || $ext === 'mov') {
                return 'video/quicktime';
            }
            if (str_starts_with($majorBrand, '3g')) {
                return 'video/3gpp';
            }

            return 'video/mp4';
        }

        // --- 7. FLV (Flash Video) ---
        if (str_starts_with($sample, "FLV\x01")) {
            return 'video/x-flv';
        }

        // --- 8. ASF / WMV / WMA ---
        if (str_starts_with($sample, "\x30\x26\xB2\x75\x8E\x66\xCF\x11")) {
            return $ext === 'wma' ? 'audio/x-ms-wma' : 'video/x-ms-wmv';
        }

        // --- 9. AIFF Audio ---
        if (str_starts_with($sample, 'FORM') && $len >= 12 && substr($sample, 8, 4) === 'AIFF') {
            return 'audio/x-aiff';
        }

        // --- 10. MIDI Audio ---
        if (str_starts_with($sample, 'MThd')) {
            return 'audio/midi';
        }

        // --- 11. AMR Audio ---
        if (str_starts_with($sample, '#!AMR')) {
            return 'audio/amr';
        }

        // --- 12. MPEG Program Stream ---
        if (str_starts_with($sample, "\x00\x00\x01\xBA") || str_starts_with($sample, "\x00\x00\x01\xB3")) {
            return 'video/mpeg';
        }

        // --- 13. MPEG Transport Stream (TS) ---
        if ($len >= 189 && $sample[0] === "\x47" && $sample[188] === "\x47") {
            return 'video/mp2t';
        }

        return null;
    }

    public function isVideo(string $mimeType): bool
    {
        return str_starts_with($mimeType, 'video/');
    }

    public function isAudio(string $mimeType): bool
    {
        return str_starts_with($mimeType, 'audio/');
    }

    public function isVideoExtension(string $extension): bool
    {
        $ext = strtolower(ltrim($extension, '.'));
        return isset(self::EXTENSION_MAP[$ext]) && str_starts_with(self::EXTENSION_MAP[$ext], 'video/');
    }

    public function isAudioExtension(string $extension): bool
    {
        $ext = strtolower(ltrim($extension, '.'));
        return isset(self::EXTENSION_MAP[$ext]) && str_starts_with(self::EXTENSION_MAP[$ext], 'audio/');
    }

    protected function extractExtension(string $path): string
    {
        $ext = pathinfo($path, PATHINFO_EXTENSION);
        return strtolower(trim($ext));
    }

    protected function extractSample(mixed $source): ?string
    {
        if (is_string($source)) {
            if (strlen($source) <= 512 && !str_contains($source, "\x00") && @file_exists($source) && is_readable($source)) {
                $handle = @fopen($source, 'rb');
                if ($handle !== false) {
                    $sample = fread($handle, 256);
                    fclose($handle);
                    return $sample !== false ? $sample : null;
                }
            }
            return $source;
        }

        if (is_resource($source) && get_resource_type($source) === 'stream') {
            $pos = ftell($source);
            $sample = fread($source, 256);
            if ($pos !== false) {
                fseek($source, $pos, SEEK_SET);
            }
            return $sample !== false ? $sample : null;
        }

        return null;
    }
}

