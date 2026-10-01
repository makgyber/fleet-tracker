<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'mapbox' => [
        // Server-side token used for Directions/Optimization API calls.
        'token' => env('MAPBOX_TOKEN'),
        'profile' => env('MAPBOX_PROFILE', 'mapbox/driving'),
        'base_url' => env('MAPBOX_BASE_URL', 'https://api.mapbox.com'),
    ],

    'tbss' => [
        // Base URL of the tbss API and a Sanctum service token used to pull the
        // daily field schedule (teams + destinations).
        'url' => env('TBSS_API_URL'),
        'token' => env('TBSS_API_TOKEN'),
    ],

];
