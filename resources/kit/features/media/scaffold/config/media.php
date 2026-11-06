<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Image Library
    |--------------------------------------------------------------------------
    |
    | The disk the library writes to. It is public: the library holds images
    | the app shows, such as profile photos, never a private document. Each
    | image remembers its disk (`media_items.disk`), so a switch to another
    | disk only touches new images.
    |
    | Uploads are scaled down to `max_side` pixels on their longest side and
    | stored as webp. `max_dimension` refuses a larger picture before it is
    | decoded, because decoding takes about four bytes of memory per pixel.
    |
    */

    'disk' => env('MEDIA_DISK', 'public'),

    'directory' => 'media',

    'max_kb' => (int) env('MEDIA_MAX_KB', 10240),

    'max_files' => (int) env('MEDIA_MAX_FILES', 10),

    'max_dimension' => (int) env('MEDIA_MAX_DIMENSION', 8000),

    'extensions' => ['jpg', 'jpeg', 'png', 'webp'],

    'max_side' => 2000,

    'quality' => 85,

    'thumbnail' => [
        'size' => 400,
        'quality' => 80,
    ],

];
