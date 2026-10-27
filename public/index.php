<?php

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

// OPcache verifică fișierele de cod doar o dată la un minut (opcache.revalidate_freq din .user.ini): mult mai repede pe hostingul comun.
// După o actualizare (Updater lasă semnul), cache-ul se golește o dată, la prima cerere, ca să nu rămână cod vechi.
if (function_exists('opcache_reset') && is_file($stamp = __DIR__.'/../bootstrap/cache/opcache-stamp') && @file_get_contents($stamp) !== @file_get_contents($stamp.'.done')) {
    @copy($stamp, $stamp.'.done');
    @opcache_reset();
}

// Mentenanța (artisan down, în timpul unei actualizări): răspunde 503 înainte să pornească aplicația, ca nicio cerere să nu dea peste
// cache-urile golite. storage/ stă în data/ când există (App\Support\Installer::paths), deci semnul e căutat acolo.
$storage = is_dir(__DIR__.'/../data/storage/framework') ? __DIR__.'/../data/storage' : __DIR__.'/../storage';
if (file_exists($maintenance = $storage.'/framework/maintenance.php')) {
    require $maintenance;
}

// Register the Composer autoloader...
require __DIR__.'/../vendor/autoload.php';

// Bootstrap Laravel and handle the request...
/** @var Application $app */
$app = require_once __DIR__.'/../bootstrap/app.php';

$app->handleRequest(Request::capture());
