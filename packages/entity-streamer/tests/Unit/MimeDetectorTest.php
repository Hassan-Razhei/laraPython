<?php

declare(strict_types=1);

namespace EntityStreamer\Tests\Unit;

use EntityStreamer\Support\MimeDetector;
use PHPUnit\Framework\TestCase;

class MimeDetectorTest extends TestCase
{
    private MimeDetector $detector;

    protected function setUp(): void
    {
        parent::setUp();
        $this->detector = new MimeDetector();
    }

    public function test_detects_all_major_video_extensions(): void
    {
        $videoExtensions = [
            'mp4' => 'video/mp4',
            'm4v' => 'video/mp4',
            'webm' => 'video/webm',
            'mkv' => 'video/x-matroska',
            'mov' => 'video/quicktime',
            'qt' => 'video/quicktime',
            'avi' => 'video/x-msvideo',
            'wmv' => 'video/x-ms-wmv',
            'flv' => 'video/x-flv',
            'ogv' => 'video/ogg',
            'ts' => 'video/mp2t',
            '3gp' => 'video/3gpp',
            '3g2' => 'video/3gpp2',
            'mpeg' => 'video/mpeg',
            'mpg' => 'video/mpeg',
        ];

        foreach ($videoExtensions as $ext => $expectedMime) {
            $detected = $this->detector->detect("lecture.{$ext}");
            $this->assertSame($expectedMime, $detected, "Failed detecting video extension: {$ext}");
            $this->assertTrue($this->detector->isVideo($detected));
            $this->assertTrue($this->detector->isVideoExtension($ext));
        }
    }

    public function test_detects_all_major_audio_extensions(): void
    {
        $audioExtensions = [
            'mp3' => 'audio/mpeg',
            'wav' => 'audio/wav',
            'wave' => 'audio/wav',
            'm4a' => 'audio/mp4',
            'aac' => 'audio/aac',
            'flac' => 'audio/flac',
            'ogg' => 'audio/ogg',
            'oga' => 'audio/ogg',
            'opus' => 'audio/opus',
            'weba' => 'audio/webm',
            'wma' => 'audio/x-ms-wma',
            'aif' => 'audio/x-aiff',
            'aiff' => 'audio/x-aiff',
            'mid' => 'audio/midi',
            'midi' => 'audio/midi',
            'amr' => 'audio/amr',
            'ac3' => 'audio/ac3',
        ];

        foreach ($audioExtensions as $ext => $expectedMime) {
            $detected = $this->detector->detect("recitation.{$ext}");
            $this->assertSame($expectedMime, $detected, "Failed detecting audio extension: {$ext}");
            $this->assertTrue($this->detector->isAudio($detected));
            $this->assertTrue($this->detector->isAudioExtension($ext));
        }
    }

    public function test_detects_from_magic_bytes(): void
    {
        // FLAC magic bytes
        $flacSample = "fLaC\x00\x00\x00\x22";
        $this->assertSame('audio/flac', $this->detector->detect('unknown_file', $flacSample));

        // WAV magic bytes: RIFF....WAVE
        $wavSample = "RIFF\x24\x00\x00\x00WAVEfmt ";
        $this->assertSame('audio/wav', $this->detector->detect('unknown_file', $wavSample));

        // AVI magic bytes: RIFF....AVI 
        $aviSample = "RIFF\x24\x00\x00\x00AVI LIST";
        $this->assertSame('video/x-msvideo', $this->detector->detect('unknown_file', $aviSample));

        // MP4 magic bytes: ....ftypisom
        $mp4Sample = "\x00\x00\x00\x20ftypisom\x00\x00\x02\x00";
        $this->assertSame('video/mp4', $this->detector->detect('unknown_file', $mp4Sample));

        // M4A magic bytes: ....ftypM4A 
        $m4aSample = "\x00\x00\x00\x20ftypM4A \x00\x00\x02\x00";
        $this->assertSame('audio/mp4', $this->detector->detect('unknown_file', $m4aSample));

        // ID3 tag (MP3)
        $id3Sample = "ID3\x03\x00\x00\x00";
        $this->assertSame('audio/mpeg', $this->detector->detect('unknown_file', $id3Sample));

        // EBML Matroska
        $mkvSample = "\x1A\x45\xDF\xA3\x9F\x42\x86\x81\x01\x42\xF7\x81\x01\x42\xF2\x81\x04\x42\xF3\x81\x08\x42\x82\x88matroska";
        $this->assertSame('video/x-matroska', $this->detector->detect('unknown_file', $mkvSample));

        // Ogg container
        $oggSample = "OggS\x00\x02\x00\x00";
        $this->assertSame('audio/ogg', $this->detector->detect('unknown_file', $oggSample));
    }

    public function test_fallback_when_undetermined(): void
    {
        $this->assertSame('application/octet-stream', $this->detector->detect('sample.xyz'));
        $this->assertSame('video/mp4', $this->detector->detect('sample.mp4', null, 'custom/fallback'));
    }
}

