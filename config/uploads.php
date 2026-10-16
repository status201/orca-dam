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
    | Each extension maps to the Content-Type its objects are stored and served
    | with. That type is never taken from the client's claim or sniffed from the
    | bytes — the uploader controls both, and either could otherwise put
    | text/html behind an inline extension (upload-policy
    | REQ-content-type-from-extension).
    |
    */

    'allowed_extensions' => [
        // Raster images
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        // Vector (sanitized on upload)
        'svg' => 'image/svg+xml',
        // Documents
        'pdf' => 'application/pdf',
        // Video
        'mp4' => 'video/mp4',
        'webm' => 'video/webm',
        'mov' => 'video/quicktime',
        // PostScript
        'eps' => 'application/postscript',
        // Audio
        'mp3' => 'audio/mpeg',
        'wav' => 'audio/wav',
        'ogg' => 'audio/ogg',
        'm4a' => 'audio/mp4',
        'aac' => 'audio/aac',
        'flac' => 'audio/flac',
        // Office
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'doc' => 'application/msword',
        'xls' => 'application/vnd.ms-excel',
        'ppt' => 'application/vnd.ms-powerpoint',
        'odt' => 'application/vnd.oasis.opendocument.text',
        'ods' => 'application/vnd.oasis.opendocument.spreadsheet',
        'odp' => 'application/vnd.oasis.opendocument.presentation',
        // Markup / text
        'tex' => 'application/x-tex',
        'mml' => 'application/mathml+xml',
        'txt' => 'text/plain',
        'md' => 'text/markdown',
        'csv' => 'text/csv',
        // Archives
        'zip' => 'application/zip',
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
