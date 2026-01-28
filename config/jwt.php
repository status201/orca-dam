<?php

return [

    /*
    |--------------------------------------------------------------------------
    | JWT Authentication Enabled
    |--------------------------------------------------------------------------
    |
    | This option enables or disables JWT authentication. When disabled, only
    | Sanctum tokens will be accepted. Enable this when you need external
    | systems to generate short-lived JWTs for frontend RTE integrations.
    |
    */

    'enabled' => env('JWT_ENABLED', false),

    /*
    |--------------------------------------------------------------------------
    | JWT Algorithm
    |--------------------------------------------------------------------------
    |
    | The algorithm used for JWT signature verification. ORCA uses symmetric
    | algorithms with per-user secrets. HS256 is recommended and widely
    | supported by JWT libraries in all languages.
    |
    */

    'algorithm' => env('JWT_ALGORITHM', 'HS256'),

    /*
    |--------------------------------------------------------------------------
    | Maximum Token Lifetime (TTL)
    |--------------------------------------------------------------------------
    |
    | The maximum allowed lifetime for JWT tokens in seconds. Even if a token
    | has a longer expiry, it will be rejected if it's older than this value.
    | Set to 0 to disable this check (not recommended).
    |
    */

    'max_ttl' => env('JWT_MAX_TTL', 36000), // 10 hours

    /*
    |--------------------------------------------------------------------------
    | Clock Skew Leeway
    |--------------------------------------------------------------------------
    |
    | The number of seconds of leeway to allow when validating token timestamps.
    | This helps handle minor clock differences between systems.
    |
    */

    'leeway' => env('JWT_LEEWAY', 60),

    /*
    |--------------------------------------------------------------------------
    | Required Claims
    |--------------------------------------------------------------------------
    |
    | The JWT claims that must be present for a token to be valid.
    | - sub: Subject (user ID)
    | - exp: Expiration time
    | - iat: Issued at time
    |
    */

    'required_claims' => ['sub', 'exp', 'iat'],

    /*
    |--------------------------------------------------------------------------
    | Issuer Validation
    |--------------------------------------------------------------------------
    |
    | If set, the 'iss' (issuer) claim in the JWT must match this value.
    | Leave null to skip issuer validation.
    |
    */

    'issuer' => env('JWT_ISSUER', null),

];
