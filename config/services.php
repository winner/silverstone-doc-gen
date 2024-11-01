<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'adobe' => [
        'client_id' => env('ADOBE_CLIENT_ID'),
        'client_secret' => env('ADOBE_CLIENT_SECRET'),
        'organization_id' => env('ADOBE_ORGANIZATION_ID'),
        'organization_domain' => env('ADOBE_ORGANIZATION_DOMAIN'),
        'callback_domain' => env('ADOBE_CALLBACK_DOMAIN'),
    ],

    'veryfi' => [
        'client_id' => env('VERYFI_CLIENT_ID'),
        'client_secret' => env('VERYFI_CLIENT_SECRET'),
        'username' => env('VERYFI_USERNAME'),
        'api_key' => env('VERYFI_API_KEY'),
    ],
];
