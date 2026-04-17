<?php

declare(strict_types=1);

use Illuminate\Container\Container;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Facade;

$container      = new Container;
$base           = dirname(__DIR__) . '/app';
$allowedDirs    = ['Controllers', 'Repositories', 'Services', 'Middleware'];

foreach ($allowedDirs as $dir) {
    foreach (glob("{$base}/{$dir}/*.php") as $file) {
        $class = 'App\\' . $dir . '\\' . basename($file, '.php');
        $container->singleton($class);
    }
}

$container->singleton('http', fn() => new HttpFactory);
Facade::setFacadeApplication($container);

return $container;