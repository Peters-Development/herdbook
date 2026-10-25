<?php

/*
|--------------------------------------------------------------------------
| Cross-origin requests
|--------------------------------------------------------------------------
|
| None: the panel and the status screen (/screen, /api/screen) are served
| from their own origin, and nothing else should read them. Laravel's
| default would answer every api/* request with "Access-Control-Allow-Origin: *".
|
*/

return [

    'paths' => [],

    'allowed_methods' => [],

    'allowed_origins' => [],

    'allowed_origins_patterns' => [],

    'allowed_headers' => [],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,

];
