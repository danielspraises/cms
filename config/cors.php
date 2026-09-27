<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Determines which cross-origin requests browsers will allow through to
    | this API. Our frontend runs on a different origin (localhost:5173)
    | than the API (127.0.0.1:8000), so without this, the browser blocks
    | every request before it even reaches Laravel.
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => ['http://localhost:5173'],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    // false is correct here — we authenticate with a Bearer token in the
    // Authorization header (see AuthController), not cookies, so
    // credentialed CORS requests aren't needed.
    'supports_credentials' => false,

];
