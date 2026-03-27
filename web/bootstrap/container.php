<?php

declare(strict_types=1);

use Illuminate\Container\Container;

$container      = new Container;
$base           = dirname(__DIR__) . '/app';
$allowedDirs    = ['Controllers', 'Repositories', 'Services'];

foreach ($allowedDirs as $dir) {
    foreach (glob("{$base}/{$dir}/*.php") as $file) {
        $class = 'App\\' . $dir . '\\' . basename($file, '.php');
        $container->singleton($class);
    }
}

return $container;