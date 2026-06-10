<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Allowed upload extensions
    |--------------------------------------------------------------------------
    |
    | The file extensions accepted by the asset uploaders (direct, chunked, and
    | replace). Anything not listed is rejected at validation time. SVGs are
    | additionally sanitized before storage (see S3Service::putUploadedFile).
    |
    */

    'allowed_extensions' => [
        // Raster images
        'jpg', 'jpeg', 'png', 'gif', 'webp',
        // Vector (sanitized on upload)
        'svg',
        // Documents
        'pdf',
        // Video
        'mp4', 'webm', 'mov',
        // PostScript
        'eps',
        // Audio
        'mp3', 'wav', 'ogg', 'm4a', 'aac', 'flac',
        // Office
        'docx', 'xlsx', 'pptx', 'doc', 'xls', 'ppt', 'odt', 'ods', 'odp',
        // Markup / text
        'tex', 'mml', 'txt', 'md', 'csv',
        // Archives
        'zip',
    ],

    /*
    |--------------------------------------------------------------------------
    | Inline-serveable extensions
    |--------------------------------------------------------------------------
    |
    | Extensions safe to serve inline in the browser. Everything else is stored
    | with "Content-Disposition: attachment" so it downloads instead of
    | rendering — defense-in-depth against MIME-sniffing and active markup.
    | SVG is inline-able only because it is sanitized on upload.
    |
    */

    'inline_extensions' => [
        'jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'pdf',
        'mp4', 'webm', 'mov', 'mp3', 'wav', 'ogg', 'm4a', 'aac', 'flac',
    ],

];
