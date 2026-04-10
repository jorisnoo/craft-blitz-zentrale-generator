<?php

use craft\helpers\App;

return [
    'apiUrl' => 'https://zentrale.noo.work/api/cache/warm',
    'apiKey' => App::env('ZENTRALE_API_KEY'),
    'warmingMode' => 'origin',
];
