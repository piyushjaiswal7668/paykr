<?php

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;

$root = dirname(__DIR__, 2);
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$file = realpath($root.'/public'.$path);
if ($file && str_starts_with($file, $root.'/public/') && is_file($file)) {
    return false;
}
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$request = Request::capture();
$app->instance('request', $request);
$kernel->bootstrap();
if (! app()->environment('testing') || ! str_starts_with(basename(config('database.connections.sqlite.database')), 'paykr-checkout-')) {
    throw new RuntimeException('Test isolation failed');
}
config(['queue.connections.redis.driver' => 'sync']);
$response = $kernel->handle($request);
$response->send();
$kernel->terminate($request, $response);
