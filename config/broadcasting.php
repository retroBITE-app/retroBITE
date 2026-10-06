<?php

// Keys the container generated at start when .env sets none; see
// docker/web/reverb.sh. The environment wins when it has them.
$generatedReverbKeys = (static function (): array {
    // storage/state, a volume; storage/framework before it was one.
    $file = current(array_filter(
        [storage_path('state/reverb.json'), storage_path('framework/reverb.json')],
        is_file(...),
    ));
    $keys = $file !== false ? json_decode((string) file_get_contents($file), true) : null;

    return is_array($keys) ? $keys : [];
})();

return [

    /*
    |--------------------------------------------------------------------------
    | Default Broadcaster
    |--------------------------------------------------------------------------
    |
    | This option controls the default broadcaster that will be used by the
    | framework when an event needs to be broadcast. You may set this to
    | any of the connections defined in the "connections" array below.
    |
    | Supported: "reverb", "pusher", "ably", "mercure", "redis", "log", "null"
    |
    */

    // Reverb without keys falls back to null rather than failing: the channels
    // in routes/channels.php build the broadcaster at boot, so a missing key
    // would otherwise take every request and every artisan command down with
    // it. The container generates keys at start (docker/web/reverb.sh); this
    // is for everywhere it has not, such as artisan run on the host.
    'default' => env('BROADCAST_CONNECTION') === 'reverb' && ! (env('REVERB_APP_KEY') ?: ($generatedReverbKeys['key'] ?? null))
        ? 'null'
        : (env('BROADCAST_CONNECTION') ?: 'null'),

    /*
    |--------------------------------------------------------------------------
    | Broadcast Connections
    |--------------------------------------------------------------------------
    |
    | Here you may define all of the broadcast connections that will be used
    | to broadcast events to other systems or over WebSockets. Samples of
    | each available type of connection are provided inside this array.
    |
    */

    'connections' => [

        // Where the app publishes to, not where browsers connect. Reverb runs
        // in the same container on 127.0.0.1, over plain http: the project has
        // no certificate, and nothing outside the container needs this port.
        // Browsers reach Reverb through nginx at /app on the page's own host;
        // see partials/head and docs/adr/0002-live-updates-over-reverb.md.
        'reverb' => [
            'driver' => 'reverb',
            'key' => env('REVERB_APP_KEY') ?: ($generatedReverbKeys['key'] ?? null),
            'secret' => env('REVERB_APP_SECRET') ?: ($generatedReverbKeys['secret'] ?? null),
            'app_id' => env('REVERB_APP_ID') ?: ($generatedReverbKeys['app_id'] ?? null),
            'options' => [
                'host' => env('REVERB_HOST') ?: '127.0.0.1',
                'port' => (int) (env('REVERB_PORT') ?: 8080),
                'scheme' => env('REVERB_SCHEME') ?: 'http',
                'useTLS' => (env('REVERB_SCHEME') ?: 'http') === 'https',
            ],
            'client_options' => [
                // Guzzle client options: https://docs.guzzlephp.org/en/stable/request-options.html
            ],
        ],

        'pusher' => [
            'driver' => 'pusher',
            'key' => env('PUSHER_APP_KEY'),
            'secret' => env('PUSHER_APP_SECRET'),
            'app_id' => env('PUSHER_APP_ID'),
            'options' => [
                'cluster' => env('PUSHER_APP_CLUSTER'),
                'host' => env('PUSHER_HOST') ?: 'api-'.env('PUSHER_APP_CLUSTER', 'mt1').'.pusher.com',
                'port' => env('PUSHER_PORT', 443),
                'scheme' => env('PUSHER_SCHEME', 'https'),
                'encrypted' => true,
                'useTLS' => env('PUSHER_SCHEME', 'https') === 'https',
            ],
            'client_options' => [
                // Guzzle client options: https://docs.guzzlephp.org/en/stable/request-options.html
            ],
        ],

        'ably' => [
            'driver' => 'ably',
            'key' => env('ABLY_KEY'),
        ],

        'mercure' => [
            'driver' => 'mercure',
            'url' => env('MERCURE_URL'),
            'public_url' => env('MERCURE_PUBLIC_URL'),
            'secret' => env('MERCURE_JWT_SECRET'),
            'encryption_key' => env('MERCURE_ENCRYPTION_KEY'),
            'claims' => [
                'iss' => env('MERCURE_JWT_ISSUER'),
                'client_id' => env('APP_NAME'),
            ],
            'cookie_name' => env('MERCURE_COOKIE_NAME'),
            'subscribe_expiration' => (int) env('MERCURE_SUBSCRIBE_EXPIRATION', 5),
        ],

        'log' => [
            'driver' => 'log',
        ],

        'null' => [
            'driver' => 'null',
        ],

    ],

];
