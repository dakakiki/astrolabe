<?php

namespace App\Support\Attachments;

/**
 * Removes metadata from uploaded images without re-encoding them (Phase 8a):
 * a phone photo of a birth certificate carries the GPS position of where it
 * was taken, the camera and often the owner's name. Pixels stay byte for byte.
 *
 * - JPEG: drops Exif and XMP (APP1), Photoshop/IPTC (APP13), APP12 and
 *   comments, the multi-picture index (MPF in APP2) and anything after the
 *   image's end marker (further pictures of an MPO, each with its own Exif).
 *   Keeps JFIF, the colour profile (ICC in APP2) and Adobe (APP14). The Exif
 *   orientation is written back as a minimal Exif block with only that tag,
 *   so portrait photos stay upright.
 * - PNG: drops eXIf, tEXt, zTXt, iTXt and tIME chunks.
 * - WebP: drops the EXIF and XMP chunks and their flags in VP8X.
 *
 * A file it cannot read as the format it claims is left as it is (fileinfo
 * already vouched for the type); it returns whether anything was removed.
 */
final class ImageMetadata
{
    public static function strip(string $path, string $mime): bool
    {
        $data = @file_get_contents($path);

        if ($data === false || $data === '') {
            return false;
        }

        $clean = match ($mime) {
            'image/jpeg' => self::jpeg($data),
            'image/png' => self::png($data),
            'image/webp' => self::webp($data),
            default => null,
        };

        if ($clean === null || $clean === $data) {
            return false;
        }

        return file_put_contents($path, $clean) !== false;
    }

    public static function jpeg(string $data): ?string
    {
        if (! str_starts_with($data, "\xFF\xD8")) {
            return null;
        }

        $length = strlen($data);
        $offset = 2;
        $segments = [];
        $orientation = null;

        while ($offset + 4 <= $length) {
            if ($data[$offset] !== "\xFF") {
                return null;
            }

            $marker = ord($data[$offset + 1]);

            if ($marker === 0xFF) {
                $offset++; // fill byte

                continue;
            }

            if ($marker === 0xD9) {
                return null; // end of image before any scan
            }

            $size = unpack('n', substr($data, $offset + 2, 2))[1];
            if ($size < 2 || $offset + 2 + $size > $length) {
                return null;
            }

            $segment = substr($data, $offset, 2 + $size);
            $payload = substr($segment, 4);

            if ($marker === 0xDA) {
                // Start of scan: the image data follows; keep it up to the end marker.
                $end = self::endOfImage($data, $offset + 2 + $size);
                if ($end === null) {
                    return null;
                }

                // The orientation goes right after JFIF, or first when there is none.
                if ($orientation !== null && $orientation > 1) {
                    $afterJfif = isset($segments[0]) && str_starts_with($segments[0], "\xFF\xE0") ? 1 : 0;
                    array_splice($segments, $afterJfif, 0, [self::orientationExif($orientation)]);
                }

                return "\xFF\xD8".implode('', $segments).substr($data, $offset, $end - $offset);
            }

            if ($marker === 0xE1 && str_starts_with($payload, "Exif\0\0")) {
                $orientation ??= self::orientationFromExif(substr($payload, 6));
            }

            $drop = in_array($marker, [0xE1, 0xEC, 0xED, 0xFE], true)
                || ($marker === 0xE2 && str_starts_with($payload, "MPF\0"));

            if (! $drop) {
                $segments[] = $segment;
            }

            $offset += 2 + $size;
        }

        return null;
    }

    public static function png(string $data): ?string
    {
        $signature = "\x89PNG\r\n\x1A\n";

        if (! str_starts_with($data, $signature)) {
            return null;
        }

        $length = strlen($data);
        $offset = 8;
        $out = $signature;

        while ($offset + 12 <= $length) {
            $size = unpack('N', substr($data, $offset, 4))[1];
            $type = substr($data, $offset + 4, 4);
            $chunk = substr($data, $offset, 12 + $size);

            if ($offset + 12 + $size > $length) {
                return null;
            }

            if (! in_array($type, ['eXIf', 'tEXt', 'zTXt', 'iTXt', 'tIME'], true)) {
                $out .= $chunk;
            }

            $offset += 12 + $size;

            if ($type === 'IEND') {
                return $out;
            }
        }

        return null;
    }

    public static function webp(string $data): ?string
    {
        if (strlen($data) < 12 || ! str_starts_with($data, 'RIFF') || substr($data, 8, 4) !== 'WEBP') {
            return null;
        }

        $length = strlen($data);
        $offset = 12;
        $chunks = '';
        $changed = false;

        while ($offset + 8 <= $length) {
            $type = substr($data, $offset, 4);
            $size = unpack('V', substr($data, $offset + 4, 4))[1];
            $padded = $size + ($size % 2);

            if ($offset + 8 + $size > $length) {
                return null;
            }

            $chunk = substr($data, $offset, 8 + $padded);

            if ($type === 'EXIF' || $type === 'XMP ') {
                $changed = true;
            } else {
                if ($type === 'VP8X' && $size >= 1) {
                    // Flags: ICC 0x20, alpha 0x10, Exif 0x08, XMP 0x04, animation 0x02.
                    $chunk[8] = chr(ord($chunk[8]) & ~0x0C);
                }
                $chunks .= $chunk;
            }

            $offset += 8 + $padded;
        }

        if (! $changed) {
            return $data;
        }

        return 'RIFF'.pack('V', 4 + strlen($chunks)).'WEBP'.$chunks;
    }

    /**
     * Position just after the image's end marker (FF D9). Coded data escapes FF
     * as FF 00 and has restart markers; the segments between the scans of a
     * progressive JPEG are stepped over by their length (and kept as they are).
     */
    private static function endOfImage(string $data, int $from): ?int
    {
        $length = strlen($data);
        $i = $from;

        while ($i < $length - 1) {
            if ($data[$i] !== "\xFF") {
                $i++;

                continue;
            }

            $next = ord($data[$i + 1]);

            if ($next === 0xD9) {
                return $i + 2;
            }

            if ($next === 0xFF) {
                $i++;
            } elseif ($next === 0x00 || ($next >= 0xD0 && $next <= 0xD7)) {
                $i += 2;
            } else {
                if ($i + 4 > $length) {
                    return null;
                }

                $size = unpack('n', substr($data, $i + 2, 2))[1];
                if ($size < 2) {
                    return null;
                }

                $i += 2 + $size;
            }
        }

        return null;
    }

    /** The Orientation tag (0x0112) of IFD0 in a TIFF block, if it is there. */
    private static function orientationFromExif(string $tiff): ?int
    {
        if (strlen($tiff) < 8) {
            return null;
        }

        $big = match (substr($tiff, 0, 2)) {
            'MM' => true,
            'II' => false,
            default => null,
        };

        if ($big === null) {
            return null;
        }

        $short = fn (int $at) => unpack($big ? 'n' : 'v', substr($tiff, $at, 2))[1];
        $long = fn (int $at) => unpack($big ? 'N' : 'V', substr($tiff, $at, 4))[1];

        $ifd = $long(4);
        if ($ifd + 2 > strlen($tiff)) {
            return null;
        }

        $entries = $short($ifd);

        for ($i = 0; $i < $entries; $i++) {
            $entry = $ifd + 2 + $i * 12;
            if ($entry + 12 > strlen($tiff)) {
                return null;
            }

            if ($short($entry) === 0x0112) {
                $value = $short($entry + 8);

                return $value >= 1 && $value <= 8 ? $value : null;
            }
        }

        return null;
    }

    /** An APP1 Exif segment with one tag: Orientation. */
    private static function orientationExif(int $orientation): string
    {
        $tiff = 'MM'.pack('n', 42).pack('N', 8)   // header, IFD0 right after it
            .pack('n', 1)                           // one entry
            .pack('nnN', 0x0112, 3, 1).pack('n', $orientation)."\0\0"
            .pack('N', 0);                          // no next IFD

        $payload = "Exif\0\0".$tiff;

        return "\xFF\xE1".pack('n', 2 + strlen($payload)).$payload;
    }
}
