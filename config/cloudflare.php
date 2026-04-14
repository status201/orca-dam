<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Enable Cloudflare Cache Purging
    |--------------------------------------------------------------------------
    |
    | When enabled, ORCA will purge Cloudflare's cache for asset URLs
    | after an asset file is replaced, ensuring the new file is served.
    |
    */
    'enabled' => env('CLOUDFLARE_ENABLED', false),

    /*
    |--------------------------------------------------------------------------
    | Cloudflare API Token
    |--------------------------------------------------------------------------
    |
    | A Cloudflare API token with Zone.Cache Purge permission.
    | Generate at: https://dash.cloudflare.com/profile/api-tokens
    |
    */
    'api_token' => env('CLOUDFLARE_API_TOKEN', ''),

    /*
    |--------------------------------------------------------------------------
    | Cloudflare Zone ID
    |--------------------------------------------------------------------------
    |
    | The Zone ID for the domain serving your S3 assets.
    | Found on the domain's Overview page in Cloudflare dashboard.
    |
    */
    'zone_id' => env('CLOUDFLARE_ZONE_ID', ''),

];
