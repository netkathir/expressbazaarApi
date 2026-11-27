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

    'stripe' => [
        'key' => env('STRIPE_KEY'),
        'secret' => env('STRIPE_SECRET'),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
        'connect_webhook_secret' => env('STRIPE_CONNECT_WEBHOOK_SECRET'),
        'payout_delay_days' => (int) env('STRIPE_PAYOUT_DELAY_DAYS', 2),
    ],

    'webhook_encryption_key' => env('WEBHOOK_ENC_KEY'),

    'google_maps' => [
        'key' => env('GOOGLE_MAPS_API_KEY', env('GOOGLE_PLACES_API_KEY', env('GOOGLE_API_KEY'))),
    ],

    'zoho' => [
        'accounts_url' => env('ZOHO_ACCOUNTS_URL', 'https://accounts.zoho.eu'),
        'api_url' => env('ZOHO_API_URL', 'https://www.zohoapis.eu'),
        'organizations_api_url' => env('ZOHO_ORGANIZATIONS_API_URL', env('ZOHO_API_URL', 'https://www.zohoapis.eu').'/inventory/v1'),
        'pos_api_url' => env('ZOHO_POS_API_URL', env('ZOHO_INVENTORY_API_URL', 'https://www.zohoapis.eu/inventory/v1')),
        'inventory_api_url' => env('ZOHO_INVENTORY_API_URL', env('ZOHO_POS_API_URL', 'https://www.zohoapis.eu/inventory/v1')),
        'redirect_uri' => env('ZOHO_REDIRECT_URI', 'http://127.0.0.1:8000/zoho/callback'),
        'pos_webhook_environment' => env('ZOHO_POS_WEBHOOK_ENVIRONMENT'),
        'scopes' => env(
            'ZOHO_SCOPES',
            'ZohoInventory.settings.READ,ZohoInventory.items.READ,ZohoInventory.items.CREATE,ZohoInventory.items.UPDATE,ZohoInventory.items.DELETE,ZohoInventory.inventoryadjustments.CREATE,ZohoInventory.inventoryadjustments.READ,ZohoPOS.organizations.READ,ZohoPOSAPI.items.READ,ZohoPOSAPI.items.CREATE,ZohoPOSAPI.items.UPDATE,ZohoPOSAPI.items.DELETE,ZohoPOSAPI.inventoryadjustments.CREATE,ZohoPOSAPI.inventoryadjustments.READ'
        ),
    ],

];
