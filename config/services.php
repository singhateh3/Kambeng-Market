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

    'cloudinary' => [
        'url' => env('CLOUDINARY_URL'),
    ],

    // Google Sign-In — the frontend obtains a signed identity token
    // directly from Google Identity Services and posts only that token to
    // POST /api/auth/google; this backend verifies it independently
    // against Google's own published keys (GoogleTokenVerifier) rather
    // than trusting any claim the client sends. client_id here is the
    // OAuth 2.0 Web Client ID from Google Cloud Console — not a secret,
    // safe to also expose to the frontend as VITE_GOOGLE_CLIENT_ID, but
    // used server-side to verify the token's audience.
    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
    ],

    // Apple Sign-In. services_id is the web "Services ID" registered in
    // the Apple Developer portal — the audience the frontend's identity
    // token is issued for, verified the same way as Google's client_id
    // above. team_id/key_id/private_key are only needed if this backend
    // ever generates its own signed client-secret JWT for a
    // server-initiated Apple flow (e.g. an authorization-code exchange or
    // token refresh) — the flow actually implemented here (frontend gets
    // the identity token via Apple's JS SDK, backend only verifies it)
    // does not need them, but they're wired here so they're centrally
    // available if that's ever added. Never send private_key to the
    // frontend.
    'apple' => [
        'services_id' => env('APPLE_SERVICES_ID'),
        'team_id' => env('APPLE_TEAM_ID'),
        'key_id' => env('APPLE_KEY_ID'),
        'private_key' => env('APPLE_PRIVATE_KEY'),
    ],

    'modempay' => [
        // Server-side authenticated calls — never exposed to the frontend.
        'secret_key' => env('MODEMPAY_SECRET_KEY'),
        'webhook_secret' => env('MODEMPAY_WEBHOOK_SECRET'),
        'base_url' => env('MODEMPAY_BASE_URL', 'https://api.modempay.com'),
        // Not yet confirmed whether/where these are needed for API calls —
        // ModemPay's docs describe the public key as client-side-only and
        // never mention a Merchant ID role in requests. Wired here so
        // they're centrally available once live behavior confirms it one
        // way or the other, rather than guessed into ModemPayClient now.
        'merchant_id' => env('MODEMPAY_MERCHANT_ID'),
        'public_key' => env('MODEMPAY_PUBLIC_KEY'),
    ],

];
