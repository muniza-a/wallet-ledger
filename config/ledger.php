<?php

return [
    // Static bearer token. Read from the environment only; never hard-coded or logged.
    'api_token' => env('API_TOKEN', ''),
];
