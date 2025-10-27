<?php

return [
    'secret' => env('RECAPTCHA_SECRET_KEY',null),
    'sitekey' => env('RECAPTCHA_SITE_KEY',null),
    'options' => [
        'timeout' => 30,
    ],
];
