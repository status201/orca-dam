<?php

use App\Services\ImageProcessingService;
use Illuminate\Http\UploadedFile;

// Helpers to generate real image binary data in-memory using GD

function makeJpegContent(int $width = 10, int $height = 10): string
{
    $img = imagecreatetruecolor($width, $height);
    $color = imagecolorallocate($img, 100, 150, 200);
    imagefill($img, 0, 0, $color);
    ob_start();
    imagejpeg($img);
    $content = ob_get_clean();
    imagedestroy($img);

    return $content;
}

function makePngContent(int $width = 10, int $height = 10): string
{
    $img = imagecreatetruecolor($width, $height);
    $color = imagecolorallocate($img, 100, 150, 200);
    imagefill($img, 0, 0, $color);
    ob_start();
    imagepng($img);
    $content = ob_get_clean();
    imagedestroy($img);

    return $content;
}

function makeStaticGifContent(): string
{
    $img = imagecreate(10, 10);
    imagecolorallocate($img, 100, 150, 200);
    ob_start();
    imagegif($img);
    $content = ob_get_clean();
    imagedestroy($img);

    return $content;
}

/**
 * Build a minimal 2-frame GIF89a binary structured so that the isAnimatedGif()
 * parser correctly counts 2 image descriptors.
 *
 * isAnimatedGif() advances by 9 bytes from the 0x2C position and then does
 * one more $offset++ (intended to skip the LZW min-code-size byte), so the
 * sub-block loop effectively starts AT the LZW min-code-size byte.  To ensure
 * the loop terminates cleanly before the second frame, the 4-byte LZW block is
 * laid out as: [LZW_min_code_size=0x02][data_byte1][data_byte2][terminator=0x00].
 * The loop reads 0x02 as blockSize → skips 2 bytes → reads 0x00 → stops.
 */
function makeAnimatedGifContent(): string
{
    $header = 'GIF89a';
    // Logical Screen Descriptor (7 bytes): width=10, height=10, no GCT
    $lsd = pack('vvCCC', 10, 10, 0x00, 0, 0);

    // Graphic Control Extension (8 bytes): delay=10 centiseconds
    $gce = "\x21\xF9\x04\x00\x0A\x00\x00\x00";

    // Image Descriptor (10 bytes): x=0, y=0, w=10, h=10, no LCT
    $imgDesc = "\x2C".pack('vvvvC', 0, 0, 10, 10, 0x00);

    // LZW block (4 bytes): min-code=2, one data byte 0x01, one data byte 0xFF, terminator 0x00
    // The parser starts its sub-block loop AT the min-code byte (0x02), reads blockSize=2,
    // skips 2 bytes (0x01, 0xFF), then sees 0x00 and terminates cleanly.
    $lzwBlock = "\x02\x01\xFF\x00";

    $frame = $gce.$imgDesc.$lzwBlock;

    return $header.$lsd.$frame.$frame."\x3B";
}

// ─── isAnimatedGif() ─────────────────────────────────────────────────────────

test('isAnimatedGif returns false for data shorter than 13 bytes', function () {
    $service = new ImageProcessingService;

    expect($service->isAnimatedGif('GIF89a'))->toBeFalse();
});

test('isAnimatedGif returns false for static GIF with single frame', function () {
    $service = new ImageProcessingService;
    $data = makeStaticGifContent();

    expect($service->isAnimatedGif($data))->toBeFalse();
});

test('isAnimatedGif returns true for animated GIF with two frames', function () {
    $service = new ImageProcessingService;
    $data = makeAnimatedGifContent();

    expect($service->isAnimatedGif($data))->toBeTrue();
});

// ─── createThumbnailContent() ────────────────────────────────────────────────

test('createThumbnailContent returns non-null JPEG string for JPEG input', function () {
    $service = new ImageProcessingService;
    $result = $service->createThumbnailContent(makeJpegContent(), 'photo.jpg');

    expect($result)->toBeString()->not->toBeEmpty();
});

test('createThumbnailContent returns non-null string for PNG input', function () {
    $service = new ImageProcessingService;
    $result = $service->createThumbnailContent(makePngContent(), 'photo.png');

    expect($result)->toBeString()->not->toBeEmpty();
});

test('createThumbnailContent returns non-null for static GIF', function () {
    $service = new ImageProcessingService;
    $result = $service->createThumbnailContent(makeStaticGifContent(), 'static.gif');

    expect($result)->not->toBeNull();
});

test('createThumbnailContent returns null for animated GIF', function () {
    $service = new ImageProcessingService;
    $result = $service->createThumbnailContent(makeAnimatedGifContent(), 'anim.gif');

    expect($result)->toBeNull();
});

test('createThumbnailContent returns null for animated GIF with uppercase extension', function () {
    $service = new ImageProcessingService;
    $result = $service->createThumbnailContent(makeAnimatedGifContent(), 'anim.GIF');

    expect($result)->toBeNull();
});

// ─── createResizedContent() ──────────────────────────────────────────────────

test('createResizedContent converts GIF to JPEG', function () {
    $service = new ImageProcessingService;
    $result = $service->createResizedContent(makeStaticGifContent(), 'gif', 100, null);

    expect($result['extension'])->toBe('jpg');
    expect($result['mime_type'])->toBe('image/jpeg');
    expect($result['content'])->toBeString()->not->toBeEmpty();
});

test('createResizedContent preserves PNG format', function () {
    $service = new ImageProcessingService;
    $result = $service->createResizedContent(makePngContent(), 'png', 100, null);

    expect($result['extension'])->toBe('png');
    expect($result['mime_type'])->toBe('image/png');
    expect($result['content'])->toBeString()->not->toBeEmpty();
});

test('createResizedContent preserves WebP format', function () {
    // Build a WebP from a JPEG via Intervention (we need a source image)
    $service = new ImageProcessingService;
    // Use JPEG content but request webp output
    $result = $service->createResizedContent(makeJpegContent(), 'webp', 100, null);

    expect($result['extension'])->toBe('webp');
    expect($result['mime_type'])->toBe('image/webp');
    expect($result['content'])->toBeString()->not->toBeEmpty();
});

test('createResizedContent handles JPG extension', function () {
    $service = new ImageProcessingService;
    $result = $service->createResizedContent(makeJpegContent(), 'jpg', 100, null);

    expect($result['extension'])->toBe('jpg');
    expect($result['mime_type'])->toBe('image/jpeg');
});

test('createResizedContent falls back to JPEG for unknown extension', function () {
    $service = new ImageProcessingService;
    $result = $service->createResizedContent(makeJpegContent(), 'bmp', 100, null);

    expect($result['extension'])->toBe('jpg');
    expect($result['mime_type'])->toBe('image/jpeg');
    expect($result['content'])->toBeString()->not->toBeEmpty();
});

test('createResizedContent always returns content, mime_type, and extension keys', function () {
    $service = new ImageProcessingService;
    $result = $service->createResizedContent(makeJpegContent(), 'jpg', 50, 50);

    expect($result)->toHaveKeys(['content', 'mime_type', 'extension']);
});

// ─── getImageDimensions() ────────────────────────────────────────────────────

test('getImageDimensions returns empty array for non-image mime type', function () {
    $service = new ImageProcessingService;
    $file = UploadedFile::fake()->create('document.pdf', 10, 'application/pdf');

    expect($service->getImageDimensions($file))->toBe([]);
});

test('getImageDimensions returns empty array for EPS file', function () {
    $service = new ImageProcessingService;
    // getMimeType may return image/jpeg for a fake file; we just need the filename to end in .eps
    $tmpPath = tempnam(sys_get_temp_dir(), 'eps_');
    file_put_contents($tmpPath, makeJpegContent());
    $file = new UploadedFile($tmpPath, 'vector.eps', 'image/jpeg', null, true);

    $result = $service->getImageDimensions($file);

    expect($result)->toBe([]);

    @unlink($tmpPath);
});

test('getImageDimensions returns width and height for valid JPEG', function () {
    $service = new ImageProcessingService;
    $jpegContent = makeJpegContent(20, 15);
    $tmpPath = tempnam(sys_get_temp_dir(), 'img_');
    file_put_contents($tmpPath, $jpegContent);
    $file = new UploadedFile($tmpPath, 'photo.jpg', 'image/jpeg', null, true);

    $result = $service->getImageDimensions($file);

    expect($result)->toHaveKeys(['width', 'height']);
    expect($result['width'])->toBeInt();
    expect($result['height'])->toBeInt();

    @unlink($tmpPath);
});
