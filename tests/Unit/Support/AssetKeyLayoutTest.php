<?php

use App\Support\AssetKeyLayout;

// Pinned outputs: existing objects never move, so a change here is a data-migration bug.
// See specs/features/s3-storage.md REQ-1/REQ-6.

test('the relative folder strips the root prefix and is empty directly under the root', function () {
    $layout = new AssetKeyLayout('assets/');

    expect($layout->relativeFolder('assets/photos/2026/abc.png'))->toBe('photos/2026/')
        ->and($layout->relativeFolder('assets/abc.png'))->toBe('')
        ->and($layout->relativeFolder('elsewhere/abc.png'))->toBe('elsewhere/')
        ->and((new AssetKeyLayout(''))->relativeFolder('abc.png'))->toBe('');
});

test('a generated thumbnail keeps the source extension; an uploaded one is always .jpg', function () {
    $layout = new AssetKeyLayout('assets/');

    expect($layout->thumbnailKey('assets/photos/abc.png'))->toBe('thumbnails/photos/abc_thumb.png')
        ->and($layout->thumbnailKey('assets/a.b.jpeg'))->toBe('thumbnails/a.b_thumb.jpeg')
        ->and($layout->uploadedThumbnailKey('assets/videos/clip.mp4'))->toBe('thumbnails/videos/clip_thumb.jpg')
        ->and($layout->uploadedThumbnailKey('assets/doc.pdf'))->toBe('thumbnails/doc_thumb.jpg');
});

test('resize keys carry the size label and the output extension', function () {
    $layout = new AssetKeyLayout('assets/');

    expect($layout->resizeKey('assets/photos/abc.gif', 's', 'jpg'))->toBe('thumbnails/S/photos/abc.jpg')
        ->and($layout->resizeKey('assets/a.b.png', 'l', 'png'))->toBe('thumbnails/L/a.b.png');
});

test('only png, webp, jpg and jpeg keep their extension when resized', function () {
    expect(array_map(AssetKeyLayout::resizeOutputExtension(...), ['png', 'webp', 'jpg', 'jpeg', 'gif', 'bmp', 'tif']))
        ->toBe(['png', 'webp', 'jpg', 'jpeg', 'jpg', 'jpg', 'jpg']);
});

test('relocating keeps the stored name and swaps only the folder', function () {
    $layout = new AssetKeyLayout('assets/');

    expect($layout->relocate('thumbnails/photos/abc_thumb.png', null, 'assets/photos/abc.png', 'assets/archive/abc.png'))
        ->toBe('thumbnails/archive/abc_thumb.png')
        ->and($layout->relocate('thumbnails/M/photos/abc.png', 'm', 'assets/photos/abc.png', 'assets/abc.png'))
        ->toBe('thumbnails/M/abc.png')
        ->and($layout->relocate('thumbnails/abc_thumb.jpg', null, 'assets/abc.jpg', 'assets/new/abc.jpg'))
        ->toBe('thumbnails/new/abc_thumb.jpg');
});

test('a derived key outside the layout its original implies is not relocated', function () {
    $layout = new AssetKeyLayout('assets/');

    expect($layout->relocate('legacy/abc_thumb.jpg', null, 'assets/photos/abc.png', 'assets/archive/abc.png'))->toBeNull()
        ->and($layout->relocate('thumbnails/other/abc_thumb.jpg', null, 'assets/abc.png', 'assets/archive/abc.png'))->toBeNull();
});
