<?php

namespace Tests\Unit;

use App\Support\Attachments\ImageMetadata;
use PHPUnit\Framework\TestCase;

/**
 * Images are made here with GD and given metadata by hand, each piece marked
 * "SECRET-…" so the test can tell it is gone.
 */
class ImageMetadataTest extends TestCase
{
    public static function jpegWithMetadata(int $orientation = 6, bool $withJfif = true): string
    {
        $image = imagecreatetruecolor(4, 2);
        imagefill($image, 0, 0, imagecolorallocate($image, 200, 40, 90));
        ob_start();
        imagejpeg($image, null, 90);
        $jpeg = (string) ob_get_clean();

        // Little-endian TIFF: Orientation and a camera make.
        $make = "SECRET-CAMERA\0";
        $tiff = 'II'.pack('v', 42).pack('V', 8)
            .pack('v', 2)
            .pack('vvV', 0x0112, 3, 1).pack('v', $orientation)."\0\0"
            .pack('vvVV', 0x010F, 2, strlen($make), 8 + 2 + 2 * 12 + 4)
            .pack('V', 0)
            .$make;

        $segments = self::segment(0xE1, "Exif\0\0".$tiff)
            .self::segment(0xE1, "http://ns.adobe.com/xap/1.0/\0<x:xmpmeta>SECRET-XMP</x:xmpmeta>")
            .self::segment(0xED, "Photoshop 3.0\08BIM SECRET-IPTC")
            .self::segment(0xFE, 'SECRET-COMMENT')
            .self::segment(0xE2, "MPF\0SECRET-MPF")
            .self::segment(0xE2, "ICC_PROFILE\0\x01\x01keep-this-profile");

        $afterJfif = 4 + unpack('n', substr($jpeg, 4, 2))[1];
        $head = $withJfif ? substr($jpeg, 0, $afterJfif) : "\xFF\xD8";

        // A second picture after the end marker, as phones write them (MPO).
        return $head.$segments.substr($jpeg, $afterJfif)."\xFF\xD8SECRET-SECOND-PICTURE\xFF\xD9";
    }

    private static function segment(int $marker, string $payload): string
    {
        return "\xFF".chr($marker).pack('n', 2 + strlen($payload)).$payload;
    }

    private static function pngChunk(string $type, string $data): string
    {
        return pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));
    }

    private static function webpChunk(string $type, string $data): string
    {
        return $type.pack('V', strlen($data)).$data.(strlen($data) % 2 ? "\0" : '');
    }

    /** The Orientation tag of the first Exif block, read without the exif extension. */
    private static function orientation(string $jpeg): ?int
    {
        $at = strpos($jpeg, "Exif\0\0");
        if ($at === false) {
            return null;
        }

        $tiff = substr($jpeg, $at + 6);
        $big = str_starts_with($tiff, 'MM');
        $short = fn (int $offset) => unpack($big ? 'n' : 'v', substr($tiff, $offset, 2))[1];
        $ifd = unpack($big ? 'N' : 'V', substr($tiff, 4, 4))[1];

        for ($i = 0; $i < $short($ifd); $i++) {
            if ($short($ifd + 2 + $i * 12) === 0x0112) {
                return $short($ifd + 2 + $i * 12 + 8);
            }
        }

        return null;
    }

    public function test_a_jpeg_loses_everything_but_the_picture_its_colours_and_its_orientation(): void
    {
        $clean = ImageMetadata::jpeg(self::jpegWithMetadata(orientation: 6));

        $this->assertNotNull($clean);
        $this->assertStringNotContainsString('SECRET', $clean);
        $this->assertStringContainsString('keep-this-profile', $clean);
        $this->assertStringContainsString('JFIF', $clean);
        $this->assertSame(6, self::orientation($clean));
        $this->assertStringEndsWith("\xFF\xD9", $clean);

        $image = imagecreatefromstring($clean);
        $this->assertNotFalse($image);
        $this->assertSame([4, 2], [imagesx($image), imagesy($image)]);
    }

    public function test_an_upright_jpeg_gets_no_exif_back_and_one_without_jfif_gets_it_first(): void
    {
        $this->assertStringNotContainsString('Exif', ImageMetadata::jpeg(self::jpegWithMetadata(orientation: 1)));

        $clean = ImageMetadata::jpeg(self::jpegWithMetadata(orientation: 8, withJfif: false));
        $this->assertSame("\xFF\xD8\xFF\xE1", substr($clean, 0, 4));
        $this->assertSame(8, self::orientation($clean));
        $this->assertNotFalse(imagecreatefromstring($clean));
    }

    public function test_a_png_loses_its_text_and_exif_chunks(): void
    {
        $image = imagecreatetruecolor(3, 3);
        ob_start();
        imagepng($image);
        $png = (string) ob_get_clean();

        $end = strrpos($png, 'IEND') - 4;
        $tagged = substr($png, 0, $end)
            .self::pngChunk('tEXt', "Author\0SECRET-AUTHOR")
            .self::pngChunk('eXIf', 'MM SECRET-EXIF')
            .self::pngChunk('tIME', "\x07\xEA\x0A\x08\x0C\x00\x00")
            .substr($png, $end);

        $clean = ImageMetadata::png($tagged);

        $this->assertStringNotContainsString('SECRET', $clean);
        $this->assertStringNotContainsString('tIME', $clean);
        $this->assertSame($png, $clean);
    }

    public function test_a_webp_loses_its_exif_and_xmp_chunks_and_flags(): void
    {
        $image = imagecreatetruecolor(5, 3);
        ob_start();
        imagewebp($image);
        $simple = (string) ob_get_clean();

        $vp8x = self::webpChunk('VP8X', chr(0x08 | 0x04)."\0\0\0".substr(pack('V', 4), 0, 3).substr(pack('V', 2), 0, 3));
        $chunks = $vp8x.substr($simple, 12).self::webpChunk('EXIF', 'SECRET-EXIF').self::webpChunk('XMP ', '<x>SECRET-XMP</x>');
        $extended = 'RIFF'.pack('V', 4 + strlen($chunks)).'WEBP'.$chunks;

        $clean = ImageMetadata::webp($extended);

        $this->assertStringNotContainsString('SECRET', $clean);
        $this->assertSame(strlen($clean) - 8, unpack('V', substr($clean, 4, 4))[1]);
        $this->assertSame(0, ord($clean[20]) & 0x0C);

        $decoded = imagecreatefromstring($clean);
        $this->assertNotFalse($decoded);
        $this->assertSame([5, 3], [imagesx($decoded), imagesy($decoded)]);
    }

    public function test_something_it_cannot_read_is_left_alone(): void
    {
        $this->assertNull(ImageMetadata::jpeg("\xFF\xD8 not really a jpeg"));
        $this->assertNull(ImageMetadata::png('plain text'));

        $path = tempnam(sys_get_temp_dir(), 'img');
        file_put_contents($path, 'plain text');
        $this->assertFalse(ImageMetadata::strip($path, 'image/jpeg'));
        $this->assertSame('plain text', file_get_contents($path));
        unlink($path);
    }
}
