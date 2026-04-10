<?php

use craft\helpers\App;

return [
    'apiUrl' => App::env('ZENTRALE_API_URL'),
    'apiKey' => App::env('ZENTRALE_API_KEY'),
    'warmingMode' => 'origin',
];
