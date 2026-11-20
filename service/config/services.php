<?php

return [

    'platform' => [
        'base_url' => env('PLATFORM_BASE_URL', 'http://platform-mock:8080'),
        'application_id' => env('PLATFORM_APPLICATION_ID', 'APP_000123'),
        'password' => env('PLATFORM_PASSWORD'),
        'timeout_seconds' => (float) env('PLATFORM_TIMEOUT_SECONDS', 3),
        'sms_source' => env('PLATFORM_SMS_SOURCE', 'DailyPass'),
    ],

];
