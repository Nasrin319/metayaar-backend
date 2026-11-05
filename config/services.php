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

    // one-time codes by SMS: "msgway" in production, "log" writes them to the log
    'sms' => [
        'driver' => env('SMS_DRIVER', 'log'),
    ],

    // lets the admin panel tell the API the real address of who is signing in; same value in its .env.local
    'internal_api_key' => env('INTERNAL_API_KEY'),

    'msgway' => [
        'api_key' => env('MSGWAY_API_KEY'),
        // ID of the approved template on the MsgWay panel; its first parameter is the code
        'otp_template_id' => env('MSGWAY_OTP_TEMPLATE_ID'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
