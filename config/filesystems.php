<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application for file storage.
    |
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Below you may configure as many filesystem disks as necessary, and you
    | may even configure multiple disks for the same driver. Examples for
    | most supported storage drivers are configured here for reference.
    |
    | Supported drivers: "local", "ftp", "sftp", "s3"
    |
    */

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => true,
            'throw' => false,
            'report' => false,
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => rtrim((string) env('APP_URL', 'http://localhost'), '/').'/storage',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        // The ROM library, shared with the Samba/vsftpd container which mounts
        // this same host directory at /games. Deliberately outside app/public:
        // downloads go through an authed route, not a guessable URL.
        'games' => [
            'driver' => 'local',
            'root' => storage_path('app/games'),
            'serve' => false,
            'throw' => false,
            'report' => false,
        ],

        // The markdown knowledge base. Outside app/public for the same reason
        // as `games`: attachments are reached through an authed route, never a
        // guessable URL. Nothing but App\Support\DocPath resolves a path here.
        //
        // storage_path() rather than config('settings.docs_path'), which is the
        // same directory: config files load alphabetically, so settings.php is
        // not loaded yet when this one is evaluated and that call returns null.
        'docs' => [
            'driver' => 'local',
            'root' => storage_path('app/docs'),
            'serve' => false,
            'throw' => false,
            'report' => false,

            // Group-writable, unlike the local driver's 0700/0600 default. The
            // point of a file-backed knowledge base is that the files are also
            // reachable from a text editor on the host, and www-data is put in
            // the `users` group by docker/web/user-setup.sh for exactly that.
            'permissions' => [
                'file' => ['public' => 0664, 'private' => 0664],
                'dir' => ['public' => 0775, 'private' => 0775],
            ],
        ],

        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => false,
            'report' => false,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    |
    | Here you may configure the symbolic links that will be created when the
    | `storage:link` Artisan command is executed. The array keys should be
    | the locations of the links and the values should be their targets.
    |
    */

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];
