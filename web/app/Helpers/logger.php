<?php

declare(strict_types=1);

use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;

if (!function_exists('logger')) {
    function logger(): Logger
    {
        static $instance = null;

        if ($instance === null) {
            $level   = config('settings.app_debug') ? Level::Debug : Level::Warning;
            $logPath = config('settings.storage_path') . '/retrobite.log';

            $instance = new Logger('retrobite');
            $instance->pushHandler(new StreamHandler($logPath, $level));
        }

        return $instance;
    }
}
